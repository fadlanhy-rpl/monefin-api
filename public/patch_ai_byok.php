<?php
/**
 * MoneFin - Patch AI BYOK (xAI Grok & Custom Provider for b.ai / Qwen)
 *
 * Upload ke: monefin-backend/public/patch_ai_byok.php
 * Akses via: https://sk0010uoic.skipper.my.id/patch_ai_byok.php
 * HAPUS file ini setelah digunakan!
 */

header('Content-Type: text/plain; charset=utf-8');

$base = dirname(__DIR__);
echo "=== MoneFin Patch AI BYOK (xAI Grok & Custom Provider) ===\n\n";

// ── 1. Update AiProviderFactory.php ───
$factoryFile = $base . '/app/Services/Ai/AiProviderFactory.php';
$factoryCode = <<<'PHP'
<?php

namespace App\Services\Ai;

/**
 * Factory that creates the correct AI provider instance
 * based on the user's configured provider name.
 */
class AiProviderFactory
{
    /**
     * Available providers and their default models.
     * Used for validation and UI population.
     */
    public const PROVIDERS = [
        'openai' => [
            'label'  => 'OpenAI',
            'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo'],
        ],
        'gemini' => [
            'label'  => 'Google Gemini',
            'models' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash'],
        ],
        'deepseek' => [
            'label'  => 'DeepSeek',
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
        ],
        'kimi' => [
            'label'  => 'Kimi (Moonshot)',
            'models' => ['kimi-k2.6', 'kimi-k2.7-code'],
        ],
        'claude' => [
            'label'  => 'Anthropic Claude',
            'models' => ['claude-sonnet-4-5', 'claude-opus-4-5', 'claude-haiku-4-5'],
        ],
        'grok' => [
            'label'  => 'xAI (Grok)',
            'models' => ['grok-2-latest', 'grok-2-vision-1212', 'grok-beta'],
        ],
        'groq' => [
            'label'  => 'Groq (LPU Cloud)',
            'models' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768'],
        ],
        'custom' => [
            'label'     => 'Custom (OpenAI-Compatible)',
            'models'    => ['qwen-2.5-72b-instruct', 'qwen-2.5-32b-instruct', 'deepseek-r1', 'llama-3.3-70b-instruct'],
            'is_custom' => true,
        ],
    ];

    /**
     * Create a provider instance from user configuration.
     */
    public static function make(string $provider, string $apiKey, string $model, ?string $baseUrl = null): AiProvider
    {
        if ($provider === 'claude') {
            return new ClaudeProvider($apiKey, $model);
        }

        return new OpenAiCompatibleProvider($provider, $apiKey, $model, $baseUrl);
    }

    /**
     * Check whether a given provider slug is supported.
     */
    public static function isSupported(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    /**
     * Get the default model for a provider.
     */
    public static function defaultModel(string $provider): string
    {
        return self::PROVIDERS[$provider]['models'][0] ?? '';
    }
}
PHP;

if (file_put_contents($factoryFile, $factoryCode)) {
    echo "[OK] AiProviderFactory.php berhasil di-patch.\n";
} else {
    echo "[FAIL] Gagal menulis AiProviderFactory.php.\n";
}

// ── 2. Update OpenAiCompatibleProvider.php ───
$compatFile = $base . '/app/Services/Ai/OpenAiCompatibleProvider.php';
$compatCode = <<<'PHP'
<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Universal provider for OpenAI-compatible APIs.
 * Covers: OpenAI, Gemini (OpenAI-compat), DeepSeek, Kimi/Moonshot, xAI (Grok), Groq, and Custom endpoints (b.ai, etc.).
 */
class OpenAiCompatibleProvider implements AiProvider
{
    private const BASE_URLS = [
        'openai'   => 'https://api.openai.com/v1',
        'gemini'   => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'deepseek' => 'https://api.deepseek.com/v1',
        'kimi'     => 'https://api.moonshot.ai/v1',
        'grok'     => 'https://api.x.ai/v1',
        'groq'     => 'https://api.groq.com/openai/v1',
    ];

    /** Error substrings that indicate quota/balance exhaustion, per provider. */
    private const QUOTA_ERROR_SIGNATURES = [
        'insufficient_quota',
        'exceeded_current_quota_error',
        'Insufficient Balance',
        'insufficient balance',
        'rate_limit_exceeded',
        'RESOURCE_EXHAUSTED',
        'quota',
        'billing',
        'credit',
        'exceeded',
    ];

    private string $baseUrl;

    public function __construct(
        private readonly string $provider,
        private readonly string $apiKey,
        private readonly string $model,
        ?string $customBaseUrl = null,
    ) {
        if ($provider === 'custom' && !empty($customBaseUrl)) {
            $url = rtrim(trim($customBaseUrl), '/');
            if (str_ends_with($url, '/chat/completions')) {
                $url = substr($url, 0, -strlen('/chat/completions'));
            }
            $this->baseUrl = $url;
        } else {
            $this->baseUrl = self::BASE_URLS[$provider] ?? 'https://api.openai.com/v1';
        }
    }

    public function chat(array $messages, float $temperature = 0.7): string
    {
        $verifySSL = (bool) config('services.ai.verify_ssl', true);

        try {
            $response = Http::timeout(60)
                ->withOptions(['verify' => $verifySSL])
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type'  => 'application/json',
                ])
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'messages'    => $messages,
                    'temperature' => $temperature,
                ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content') ?? 'Tidak ada respons dari AI.';
            }

            $status = $response->status();
            $body   = $response->body();

            Log::warning("AI Provider [{$this->provider}] HTTP {$status}: {$body}");

            if ($this->isQuotaError($status, $body)) {
                return $this->quotaExhaustedMessage();
            }

            return match ($status) {
                401 => 'API key tidak valid atau telah dicabut. Periksa kembali API key Anda di Settings.',
                429 => $this->quotaExhaustedMessage(),
                default => "Error dari provider AI ({$status}): " . ($response->json('error.message') ?? 'Terjadi kesalahan saat memproses permintaan.'),
            };
        } catch (\Throwable $e) {
            Log::error("AI Provider [{$this->provider}] Exception: {$e->getMessage()}");

            if ($this->isQuotaError(0, $e->getMessage())) {
                return $this->quotaExhaustedMessage();
            }

            return 'Tidak dapat terhubung ke server AI. Periksa koneksi internet Anda atau coba lagi nanti.';
        }
    }

    public function streamChat(array $messages, callable $onChunk, float $temperature = 0.7): void
    {
        $verifySSL = (bool) config('services.ai.verify_ssl', true);

        $client = new \GuzzleHttp\Client([
            'timeout' => 90,
            'verify'  => $verifySSL,
        ]);

        $response = $client->post("{$this->baseUrl}/chat/completions", [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'text/event-stream',
            ],
            'json' => [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $temperature,
                'stream'      => true,
            ],
            'stream' => true,
        ]);

        $body   = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $chunk   = $body->read(256);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line   = trim($line);

                if (!str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '[DONE]') {
                    return;
                }

                $json = json_decode($data, true);
                $text = $json['choices'][0]['delta']['content'] ?? null;

                if ($text !== null && $text !== '') {
                    $onChunk($text);
                }
            }
        }
    }

    public function getProviderName(): string
    {
        return $this->provider;
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    private function isQuotaError(int $status, string $body): bool
    {
        if ($status === 429) {
            return true;
        }

        foreach (self::QUOTA_ERROR_SIGNATURES as $signature) {
            if (stripos($body, $signature) !== false) {
                return true;
            }
        }

        return false;
    }

    private function quotaExhaustedMessage(): string
    {
        $labels = [
            'openai'   => 'platform.openai.com/account/billing',
            'gemini'   => 'aistudio.google.com',
            'deepseek' => 'platform.deepseek.com',
            'kimi'     => 'platform.moonshot.cn',
            'grok'     => 'console.x.ai',
            'groq'     => 'console.groq.com',
            'custom'   => 'dashboard penyedia API kustom Anda',
        ];

        $dashboard = $labels[$this->provider] ?? 'dashboard provider Anda';

        return "QUOTA_EXCEEDED|{$this->providerLabel()}|{$dashboard}";
    }

    private function providerLabel(): string
    {
        return match ($this->provider) {
            'openai'   => 'OpenAI',
            'gemini'   => 'Google Gemini',
            'deepseek' => 'DeepSeek',
            'kimi'     => 'Kimi (Moonshot)',
            'grok'     => 'xAI (Grok)',
            'groq'     => 'Groq',
            'custom'   => 'Custom Provider',
            default    => ucfirst($this->provider),
        };
    }
}
PHP;

if (file_put_contents($compatFile, $compatCode)) {
    echo "[OK] OpenAiCompatibleProvider.php berhasil di-patch.\n";
} else {
    echo "[FAIL] Gagal menulis OpenAiCompatibleProvider.php.\n";
}

// ── 3. Update AiService.php ───
$aiServiceFile = $base . '/app/Services/AiService.php';
$aiContent = file_get_contents($aiServiceFile);
if (strpos($aiContent, '$baseUrl = $aiConfig') === false) {
    $search = 'return AiProviderFactory::make($provider, $apiKey, $model);';
    $replace = '$baseUrl = $aiConfig[\'base_url\'] ?? null;' . "\n\n        " . 'return AiProviderFactory::make($provider, $apiKey, $model, $baseUrl);';
    $aiContent = str_replace($search, $replace, $aiContent);
    file_put_contents($aiServiceFile, $aiContent);
    echo "[OK] AiService.php berhasil di-patch (baseUrl pass-through).\n";
} else {
    echo "[OK] AiService.php sudah up-to-date.\n";
}

// ── 4. Update AiController.php Secara Utuh ───
$ctrlFile = $base . '/app/Http/Controllers/AiController.php';
$ctrlCode = <<<'PHP'
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
            'base_url'   => ['nullable', 'string', 'max:500'],
        ]);

        $user  = $request->user();
        $prefs = $user->preferences ?? [];

        $prefs['ai_enabled'] = $validated['ai_enabled'];

        if (!empty($validated['provider'])) {
            $existing = $prefs['ai_config'] ?? [];

            $prefs['ai_config'] = [
                'provider' => $validated['provider'],
                'model'    => $validated['model'] ?? AiProviderFactory::defaultModel($validated['provider']),
                'base_url' => !empty($validated['base_url']) ? trim($validated['base_url']) : ($existing['base_url'] ?? null),
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
                'base_url'       => $prefs['ai_config']['base_url']      ?? null,
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
PHP;

if (file_put_contents($ctrlFile, $ctrlCode)) {
    echo "[OK] AiController.php berhasil di-patch secara utuh.\n";
} else {
    echo "[FAIL] Gagal menulis AiController.php.\n";
}

// ── 5. Update UserApiKeyService.php ───
$keyServiceFile = $base . '/app/Services/UserApiKeyService.php';
$keyCode = file_get_contents($keyServiceFile);
if (strpos($keyCode, '$aiConfig[\'api_key_encrypted\'] ??') === false) {
    $searchKey = '$encryptedKey = $aiConfig[\'api_key\'] ?? null;';
    $replaceKey = '$encryptedKey = $aiConfig[\'api_key_encrypted\'] ?? ($aiConfig[\'api_key\'] ?? null);';
    $keyCode = str_replace($searchKey, $replaceKey, $keyCode);
    file_put_contents($keyServiceFile, $keyCode);
    echo "[OK] UserApiKeyService.php berhasil di-patch.\n";
} else {
    echo "[OK] UserApiKeyService.php sudah up-to-date.\n";
}

// ── 6. Refresh Laravel Cache ───
try {
    require $base . '/vendor/autoload.php';
    $app = require_once $base . '/bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    \Illuminate\Support\Facades\Artisan::call('route:clear');
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    echo "\n[OK] Route & Config cache berhasil dibersihkan.\n";
} catch (\Throwable $e) {
    echo "\n[INFO] Artisan clear: " . $e->getMessage() . "\n";
}

echo "\n=== Patch Selesai! Silakan HAPUS file patch_ai_byok.php dari server! ===\n";
