<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\AiService;
use App\Services\FinancialHealthService;
use App\Services\Ai\AiProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AiController extends Controller
{
    public function __construct(
        private readonly AiService             $ai,
        private readonly FinancialHealthService $healthService,
    ) {}

    /**
     * POST /api/ai/chat
     */
    public function chat(Request $request): JsonResponse
    {
        $user  = $request->user();
        $prefs = $user->preferences ?? [];

        if (!($prefs['ai_enabled'] ?? false)) {
            return response()->json([
                'error'   => true,
                'message' => 'AI Chatbot belum diaktifkan. Aktifkan dan konfigurasikan API key di Settings → AI Chatbot.',
                'code'    => 'AI_DISABLED',
            ], 422);
        }

        $validated = $request->validate([
            'message'           => ['required', 'string', 'max:1000'],
            'history'           => ['nullable', 'array', 'max:20'],
            'history.*.role'    => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:2000'],
        ]);

        $reply = $this->ai->chat($user, $validated['message'], $validated['history'] ?? []);

        // Check for quota exhaustion and surface it clearly
        if ($this->ai->isQuotaError($reply)) {
            $message = $this->ai->formatQuotaError($reply);
            return response()->json([
                'error'          => true,
                'quota_exceeded' => true,
                'message'        => $message,
                'code'           => 'QUOTA_EXCEEDED',
            ], 402);
        }

        return response()->json(['data' => ['reply' => $reply]]);
    }

    /**
     * POST /api/ai/chat/stream
     * Realtime Server-Sent Events (SSE) streaming endpoint
     */
    public function stream(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user  = $request->user();
        $prefs = $user->preferences ?? [];

        if (!($prefs['ai_enabled'] ?? false)) {
            return response()->stream(function () {
                echo "data: " . json_encode(['error' => 'AI Chatbot belum diaktifkan. Aktifkan dan konfigurasikan API key di Settings → AI Chatbot.']) . "\n\n";
                echo "data: [DONE]\n\n";
                flush();
            }, 200, [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache, no-transform',
                'Connection'        => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $validated = $request->validate([
            'message'           => ['required', 'string', 'max:2000'],
            'history'           => ['nullable', 'array', 'max:20'],
            'history.*.role'    => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:2000'],
        ]);

        return response()->stream(function () use ($user, $validated) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $this->ai->streamChat(
                $user,
                $validated['message'],
                $validated['history'] ?? [],
                function (string $token) {
                    echo "data: " . json_encode(['text' => $token]) . "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            );

            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * POST /api/ai/suggest-category
     */
    public function suggestCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'type'        => ['nullable', 'in:income,expense'],
        ]);

        $user  = $request->user();
        $type  = $validated['type'] ?? 'expense';

        $categories = Category::where('user_id', $user->id)
            ->where(fn($q) => $q->where('type', $type)->orWhereNull('type'))
            ->get(['id', 'name', 'type'])
            ->toArray();

        $suggestion = $this->ai->suggestCategory($categories, $validated['description'], $type);

        return response()->json([
            'data'    => $suggestion ? ['category' => $suggestion] : null,
            'message' => $suggestion ? 'Kategori berhasil disarankan.' : 'Tidak dapat menemukan kategori yang sesuai.',
        ]);
    }

    /**
     * GET /api/ai/budget-recommendations
     */
    public function budgetRecommendations(Request $request): JsonResponse
    {
        $result = $this->ai->budgetRecommendations($request->user());
        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/ai/insights
     * Now purely deterministic — no AI required.
     */
    public function insights(Request $request): JsonResponse
    {
        $lang   = $request->header('Accept-Language') ?? ($request->user()?->preferences['language'] ?? 'id');
        $result = $this->healthService->insights($request->user(), $lang);
        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/ai/test-connection
     * Test the user's configured AI provider with a minimal message.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $user  = $request->user();
        $prefs = $user->preferences ?? [];

        if (empty($prefs['ai_config']['provider'] ?? '')) {
            return response()->json([
                'ok'      => false,
                'message' => 'Belum ada provider yang dikonfigurasi. Pilih provider dan masukkan API key terlebih dahulu.',
            ], 422);
        }

        $result = $this->ai->testConnection($user);

        return response()->json([
            'ok'       => $result['ok'],
            'provider' => $result['provider'] ?? null,
            'model'    => $result['model']    ?? null,
            'message'  => $result['message'],
        ], $result['ok'] ? 200 : 422);
    }

    /**
     * POST /api/ai/reveal-key
     * Reveal the encrypted API key after password verification.
     * Rate-limited to 5 attempts per user per 5 minutes.
     */
    public function revealKey(Request $request): JsonResponse
    {
        $user = $request->user();
        $key  = 'reveal-key:' . $user->id;

        // Rate limit: 5 attempts per 5 minutes
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        $request->validate([
            'password' => ['required', 'string'],
        ]);

        // Verify user password
        if (!Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 300); // 5 minutes decay
            $remaining = 5 - RateLimiter::attempts($key);
            return response()->json([
                'message'           => 'Password tidak valid.',
                'attempts_remaining' => max(0, $remaining),
            ], 403);
        }

        RateLimiter::clear($key);

        $prefs  = $user->preferences ?? [];
        $encKey = $prefs['ai_config']['api_key_encrypted'] ?? null;

        if (!$encKey) {
            return response()->json(['message' => 'Tidak ada API key yang tersimpan.'], 404);
        }

        try {
            $apiKey = Crypt::decryptString($encKey);
            return response()->json(['api_key' => $apiKey]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal mendekripsi API key. Silakan simpan ulang.'], 500);
        }
    }

    /**
     * GET /api/ai/providers
     * Return supported providers and their models for frontend dropdowns.
     */
    public function providers(): JsonResponse
    {
        return response()->json(['data' => AiProviderFactory::PROVIDERS]);
    }

    /**
     * POST /api/ai/save-config
     * Save AI provider configuration (provider, model, api_key, enabled).
     */
    public function saveConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_enabled' => ['required', 'boolean'],
            'provider'   => ['nullable', 'string', 'in:' . implode(',', array_keys(AiProviderFactory::PROVIDERS))],
            'model'      => ['nullable', 'string', 'max:100'],
            'api_key'    => ['nullable', 'string', 'max:500'],
        ]);

        $user  = $request->user();
        $prefs = $user->preferences ?? [];

        $prefs['ai_enabled'] = $validated['ai_enabled'];

        if (!empty($validated['provider'])) {
            $existing = $prefs['ai_config'] ?? [];

            $prefs['ai_config'] = [
                'provider' => $validated['provider'],
                'model'    => $validated['model'] ?? AiProviderFactory::defaultModel($validated['provider']),
                // Preserve existing encrypted key if no new key provided
                'api_key_encrypted' => !empty($validated['api_key'])
                    ? Crypt::encryptString($validated['api_key'])
                    : ($existing['api_key_encrypted'] ?? null),
                // Store masked version for display (first 6 + last 4 chars)
                'api_key_masked' => !empty($validated['api_key'])
                    ? $this->maskKey($validated['api_key'])
                    : ($existing['api_key_masked'] ?? null),
            ];
        }

        $user->update(['preferences' => $prefs]);

        return response()->json([
            'message'    => 'Konfigurasi AI berhasil disimpan.',
            'ai_enabled' => $prefs['ai_enabled'],
            'ai_config'  => [
                'provider'       => $prefs['ai_config']['provider']      ?? null,
                'model'          => $prefs['ai_config']['model']         ?? null,
                'api_key_masked' => $prefs['ai_config']['api_key_masked'] ?? null,
            ],
        ]);
    }

    private function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($key, 0, 6) . str_repeat('*', max(4, $len - 10)) . substr($key, -4);
    }
}
