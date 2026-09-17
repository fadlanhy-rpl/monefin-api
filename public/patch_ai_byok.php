<?php
/**
 * MoneFin - Patch AI BYOK & Custom Provider (B.AI, Grok, Groq, etc.)
 *
 * Upload ke: monefin-backend/public/patch_ai_byok.php
 * Akses via: https://sk0010uoic.skipper.my.id/patch_ai_byok.php
 * HAPUS file ini setelah digunakan!
 */

header('Content-Type: text/plain; charset=utf-8');

$base = dirname(__DIR__);
echo "=== MoneFin Patch AI BYOK & Custom Providers (B.AI / OpenCode Compatible) ===\n\n";

// ── 1. Update AiProviderFactory.php ───
$factoryFile = $base . '/app/Services/Ai/AiProviderFactory.php';
$factoryCode = <<<'EOF_FACTORY'
<?php

namespace App\Services\Ai;

/**
 * Factory that creates the correct AI provider instance
 * based on the user's configured provider name.
 */
class AiProviderFactory
{
    /**
     * Available providers and their recommended default models.
     * Note: Users are completely free to specify ANY model identifier supported by the provider.
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
            'models'    => ['qwen', 'qwen-2.5-72b-instruct', 'qwen-2.5-32b-instruct', 'deepseek-r1', 'llama-3.3-70b-instruct'],
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
        return self::PROVIDERS[$provider]['models'][0] ?? 'default';
    }
}
EOF_FACTORY;

if (file_put_contents($factoryFile, $factoryCode)) {
    echo "[OK] AiProviderFactory.php berhasil di-patch.\n";
} else {
    echo "[FAIL] Gagal menulis AiProviderFactory.php.\n";
}

// ── 2. Update OpenAiCompatibleProvider.php ───
$compatFile = $base . '/app/Services/Ai/OpenAiCompatibleProvider.php';
$compatCode = <<<'EOF_COMPAT'
<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Universal provider for OpenAI-compatible APIs.
 * Covers: OpenAI, Gemini (OpenAI-compat), DeepSeek, Kimi/Moonshot, xAI (Grok), Groq, and Custom endpoints (b.ai, OpenRouter, etc.).
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
            $payload = [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $temperature,
            ];

            if (!empty($this->model)) {
                $payload['max_tokens'] = 4096;
            }

            $response = Http::timeout(25)
                ->withOptions(['verify' => $verifySSL])
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => config('app.url', 'https://monefin.web.id'),
                    'X-Title'       => 'MoneFin',
                ])
                ->post("{$this->baseUrl}/chat/completions", $payload);

            if ($response->failed()) {
                $body = $response->json();
                Log::warning('AI provider error', [
                    'provider' => $this->provider,
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);

                return $this->handleError($body, $response->status());
            }

            $data = $response->json();
            return $data['choices'][0]['message']['content']
                ?? 'Maaf, saya tidak mendapatkan respons yang valid dari AI. Silakan coba lagi.';

        } catch (\Throwable $e) {
            Log::error('AI provider exception', [
                'provider' => $this->provider,
                'message'  => $e->getMessage(),
            ]);
            return 'Terjadi kesalahan saat menghubungi AI (' . $e->getMessage() . '). Periksa Base URL dan koneksi.';
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

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private function handleError(?array $body, int $status): string
    {
        $errorType    = $body['error']['type']    ?? '';
        $errorMessage = $body['error']['message'] ?? '';
        $errorCode    = $body['error']['code']    ?? '';

        $combined = strtolower($errorType . ' ' . $errorMessage . ' ' . $errorCode);

        foreach (self::QUOTA_ERROR_SIGNATURES as $sig) {
            if (str_contains($combined, strtolower($sig))) {
                return $this->quotaExhaustedMessage();
            }
        }

        if (!empty($errorMessage)) {
            return "Error dari {$this->providerLabel()} ({$status}): {$errorMessage}";
        }

        if ($status === 401) {
            return "API key {$this->providerLabel()} tidak valid atau sudah expired (401 Unauthorized).";
        }

        if ($status === 404) {
            return "Endpoint {$this->providerLabel()} tidak ditemukan (404 Not Found). Periksa Base URL ({$this->baseUrl}).";
        }

        if ($status === 429) {
            return $this->quotaExhaustedMessage();
        }

        return "Maaf, {$this->providerLabel()} sedang tidak tersedia (HTTP {$status}). Silakan coba beberapa saat lagi.";
    }

    public function streamChat(array $messages, callable $onChunk, float $temperature = 0.7): void
    {
        $verifySSL = (bool) config('services.ai.verify_ssl', true);

        try {
            $client = new \GuzzleHttp\Client([
                'timeout'     => 45.0,
                'verify'      => $verifySSL,
                'http_errors' => false,
            ]);

            $response = $client->post("{$this->baseUrl}/chat/completions", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'text/event-stream',
                    'HTTP-Referer'  => config('app.url', 'https://monefin.web.id'),
                    'X-Title'       => 'MoneFin',
                ],
                'json' => [
                    'model'       => $this->model,
                    'messages'    => $messages,
                    'temperature' => $temperature,
                    'stream'      => true,
                ],
                'stream' => true,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $rawBody = (string) $response->getBody();
                $errJson = json_decode($rawBody, true);
                $errMsg  = $errJson['error']['message'] ?? "Error {$statusCode} dari AI provider.";
                $onChunk("Error dari {$this->providerLabel()} ({$statusCode}): {$errMsg}");
                return;
            }

            $body = $response->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $buffer .= $body->read(256);
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if (str_starts_with($line, 'data: ')) {
                        $data = substr($line, 6);
                        if ($data === '[DONE]') {
                            return;
                        }
                        $json = json_decode($data, true);
                        $token = $json['choices'][0]['delta']['content'] ?? '';
                        if ($token !== '') {
                            $onChunk($token);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AI stream error', ['provider' => $this->provider, 'message' => $e->getMessage()]);
            $onChunk("\n[Terjadi gangguan koneksi saat streaming: " . $e->getMessage() . "]");
        }
    }

    private function quotaExhaustedMessage(): string
    {
        $dashboards = [
            'openai'   => 'platform.openai.com/account/billing',
            'gemini'   => 'aistudio.google.com',
            'deepseek' => 'platform.deepseek.com',
            'kimi'     => 'platform.moonshot.cn',
            'grok'     => 'console.x.ai',
            'groq'     => 'console.groq.com',
            'custom'   => 'dashboard provider kustom Anda',
        ];

        $dashboard = $dashboards[$this->provider] ?? 'dashboard provider Anda';
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
            'groq'     => 'Groq (LPU Cloud)',
            'custom'   => 'Custom (OpenAI-Compatible)',
            default    => ucfirst($this->provider),
        };
    }
}
EOF_COMPAT;

if (file_put_contents($compatFile, $compatCode)) {
    echo "[OK] OpenAiCompatibleProvider.php berhasil di-patch.\n";
} else {
    echo "[FAIL] Gagal menulis OpenAiCompatibleProvider.php.\n";
}

// ── 3. Update AiService.php Secara Utuh ───
$aiServiceFile = $base . '/app/Services/AiService.php';
$aiServiceCode = <<<'EOF_SERVICE'
<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiProviderFactory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class AiService
{
    public function __construct(
        private readonly UserApiKeyService $keyService = new UserApiKeyService(),
    ) {}

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Financial chat: answer user questions using their real data as context.
     * Requires AI to be enabled in user preferences.
     */
    public function chat(User $user, string $message, array $history = []): string
    {
        $provider = $this->makeProvider($user);
        if (is_string($provider)) {
            return $provider; // error message string
        }

        $context      = $this->buildUserContext($user);
        $systemPrompt = $this->buildSystemPrompt($context);

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($history as $turn) {
            if (isset($turn['role'], $turn['content'])) {
                $messages[] = [
                    'role'    => $turn['role'] === 'user' ? 'user' : 'assistant',
                    'content' => $turn['content'],
                ];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        return $provider->chat($messages);
    }

    /**
     * Realtime streaming financial chat: stream answer token by token.
     *
     * @param  callable(string $token): void  $onChunk
     */
    public function streamChat(User $user, string $message, array $history, callable $onChunk): void
    {
        $provider = $this->makeProvider($user);
        if (is_string($provider)) {
            $onChunk($provider);
            return;
        }

        $context      = $this->buildUserContext($user);
        $systemPrompt = $this->buildSystemPrompt($context);

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($history as $turn) {
            if (isset($turn['role'], $turn['content'])) {
                $messages[] = [
                    'role'    => $turn['role'] === 'user' ? 'user' : 'assistant',
                    'content' => $turn['content'],
                ];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $provider->streamChat($messages, $onChunk);
    }

    /**
     * Test connection directly using supplied credentials (used for on-the-fly testing before saving).
     */
    public function testConnectionDirect(string $provider, string $apiKey, ?string $model = null, ?string $baseUrl = null): array
    {
        if (!AiProviderFactory::isSupported($provider)) {
            return ['ok' => false, 'message' => "Provider '{$provider}' tidak didukung."];
        }

        if (empty($model)) {
            $model = AiProviderFactory::defaultModel($provider);
        }

        try {
            $instance = AiProviderFactory::make($provider, $apiKey, $model, $baseUrl);
            $response = $instance->chat([
                ['role' => 'user', 'content' => 'Reply with exactly: OK'],
            ], 0.0);

            $isQuotaError = str_starts_with($response, 'QUOTA_EXCEEDED|');
            $isError      = $isQuotaError
                || str_starts_with(strtolower($response), 'error')
                || str_contains(strtolower($response), 'error dari')
                || str_contains(strtolower($response), 'tidak valid')
                || str_contains(strtolower($response), 'tidak tersedia')
                || str_contains(strtolower($response), 'terjadi kesalahan');

            if ($isQuotaError) {
                $response = $this->formatQuotaError($response);
            }

            return [
                'ok'       => !$isError,
                'provider' => $instance->getProviderName(),
                'model'    => $instance->getModelName(),
                'message'  => $isError ? $response : 'Koneksi berhasil!',
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'message' => 'Gagal menghubungi AI: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Retrieve decrypted API key for a user without checking ai_enabled.
     */
    public function getDecryptedApiKey(User $user): ?string
    {
        $prefs    = $user->preferences ?? [];
        $aiConfig = $prefs['ai_config'] ?? [];
        $encKey   = $aiConfig['api_key_encrypted'] ?? ($aiConfig['api_key'] ?? null);

        if (!$encKey) {
            return null;
        }

        try {
            return Crypt::decryptString($encKey);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Test the user's configured AI connection with a minimal message.
     * Returns array: ['ok' => bool, 'provider' => string, 'model' => string, 'message' => string]
     */
    public function testConnection(User $user): array
    {
        $prefs    = $user->preferences ?? [];
        $aiConfig = $prefs['ai_config'] ?? [];
        $provider = $aiConfig['provider'] ?? '';
        $model    = $aiConfig['model'] ?? '';
        $baseUrl  = $aiConfig['base_url'] ?? null;
        $apiKey   = $this->getDecryptedApiKey($user);

        if (!$provider || !$apiKey) {
            return ['ok' => false, 'message' => 'Konfigurasi AI belum lengkap. Masukkan provider dan API key.'];
        }

        return $this->testConnectionDirect($provider, $apiKey, $model, $baseUrl);
    }

    /**
     * Suggest the best category for a transaction based on its description.
     */
    public function suggestCategory(array $categories, string $description, string $type = 'expense'): ?array
    {
        if (empty($categories) || empty($description)) {
            return null;
        }

        $categoryList = collect($categories)
            ->filter(fn($c) => ($c['type'] ?? $type) === $type || !isset($c['type']))
            ->map(fn($c) => "ID: {$c['id']}, Nama: {$c['name']}")
            ->join("\n");

        if (empty($categoryList)) {
            return null;
        }

        $prompt = <<<PROMPT
Kamu adalah asisten keuangan MoneFin. Tentukan SATU kategori yang paling cocok untuk transaksi berikut.

Deskripsi transaksi: "{$description}"
Tipe: {$type}

Daftar kategori yang tersedia:
{$categoryList}

Balas HANYA dengan JSON valid format: {"id": <category_id>, "confidence": <0.0-1.0>}
Jangan tambahkan teks lain di luar JSON.
PROMPT;

        $cacheKey = 'ai_cat_' . md5($description . '_' . $type . '_' . count($categories));
        return Cache::remember($cacheKey, 86400, function () use ($prompt, $categories) {
            $user = auth()->user();
            $provider = $this->makeProvider($user);
            if (is_string($provider)) {
                return null;
            }

            try {
                $raw = $provider->chat([['role' => 'user', 'content' => $prompt]], 0.1);
                $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
                $data = json_decode($clean, true);

                if (!empty($data['id'])) {
                    $matched = collect($categories)->firstWhere('id', (int) $data['id']);
                    if ($matched) {
                        return [
                            'id'         => $matched['id'],
                            'name'       => $matched['name'],
                            'confidence' => $data['confidence'] ?? 0.8,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('AI category suggestion failed', ['error' => $e->getMessage()]);
            }

            return null;
        });
    }

    /**
     * Budget recommendations based on 3-month spending history.
     */
    public function budgetRecommendations(User $user): array
    {
        $threeMonthsAgo = Carbon::now()->subMonths(3)->startOfMonth();

        $spending = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('date', '>=', $threeMonthsAgo)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, SUM(amount) as total_spent, COUNT(*) as count')
            ->groupBy('category_id')
            ->with('category:id,name')
            ->get()
            ->map(fn($t) => [
                'category_id'   => $t->category_id,
                'category_name' => $t->category?->name ?? 'Lainnya',
                'avg_monthly'   => round($t->total_spent / 3),
                'total_spent'   => (float) $t->total_spent,
                'count'         => $t->count,
            ])
            ->toArray();

        $recommendations = collect($spending)->map(function ($s) {
            $limit  = round($s['avg_monthly'] * 0.85 / 10000) * 10000;
            $limit  = max($limit, 50000);
            return [
                'category_id'       => $s['category_id'],
                'category_name'     => $s['category_name'],
                'recommended_limit' => $limit,
                'reason'            => "Rata-rata pengeluaran 3 bulan: Rp " . number_format($s['avg_monthly'], 0, ',', '.') . ". Budget hemat 85% untuk mendorong penghematan.",
            ];
        })->toArray();

        return ['recommendations' => $recommendations, 'spending_summary' => $spending];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function makeProvider(?User $user): \App\Services\Ai\AiProvider|string
    {
        if (!$user) {
            return 'AI_NOT_CONFIGURED';
        }

        $prefs = $user->preferences ?? [];
        $aiEnabled = $prefs['ai_enabled'] ?? false;

        if (!$aiEnabled) {
            return 'AI Chatbot belum diaktifkan. Aktifkan dan konfigurasikan API key di Settings → AI Chatbot.';
        }

        $aiConfig = $prefs['ai_config'] ?? [];
        $provider = $aiConfig['provider'] ?? '';
        $model    = $aiConfig['model']    ?? '';
        $encKey   = $aiConfig['api_key_encrypted'] ?? ($aiConfig['api_key'] ?? '');

        if (!$provider || !$encKey) {
            return 'Konfigurasi AI tidak lengkap. Silakan atur provider dan API key di Settings → AI Chatbot.';
        }

        if (!AiProviderFactory::isSupported($provider)) {
            return "Provider AI '{$provider}' tidak didukung. Pilih provider yang tersedia di Settings.";
        }

        $apiKey = $this->getDecryptedApiKey($user);

        if (!$apiKey) {
            return 'Gagal mendekripsi API key. Silakan simpan ulang API key di Settings → AI Chatbot.';
        }

        if (empty($model)) {
            $model = AiProviderFactory::defaultModel($provider);
        }

        $baseUrl = $aiConfig['base_url'] ?? null;

        return AiProviderFactory::make($provider, $apiKey, $model, $baseUrl);
    }

    public function formatQuotaError(string $raw): string
    {
        $parts     = explode('|', $raw);
        $provider  = $parts[1] ?? 'provider AI Anda';
        $dashboard = $parts[2] ?? 'dashboard provider';

        return "Kuota atau saldo API key {$provider} Anda telah habis. Silakan isi ulang saldo di {$dashboard} atau ganti API key di Settings → AI Chatbot.";
    }

    public function isQuotaError(string $text): bool
    {
        return str_starts_with($text, 'QUOTA_EXCEEDED|');
    }

    private function buildUserContext(User $user): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        $income = (float) Transaction::where('user_id', $user->id)
            ->where('type', 'income')
            ->where('date', '>=', $startOfMonth)
            ->sum('amount');

        $expense = (float) Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('date', '>=', $startOfMonth)
            ->sum('amount');

        $budgets = Budget::where('user_id', $user->id)
            ->where('month', Carbon::now()->format('Y-m'))
            ->with('category:id,name')
            ->get()
            ->map(fn($b) => [
                'category' => $b->category?->name ?? 'Lainnya',
                'limit'    => (float) $b->amount,
                'spent'    => (float) Transaction::where('user_id', $user->id)
                    ->where('category_id', $b->category_id)
                    ->where('type', 'expense')
                    ->where('date', '>=', $startOfMonth)
                    ->sum('amount'),
            ])
            ->toArray();

        return [
            'name'                 => $user->name,
            'current_month'        => Carbon::now()->locale('id')->isoFormat('MMMM Y'),
            'total_income_month'   => $income,
            'total_expense_month'  => $expense,
            'net_savings_month'    => $income - $expense,
            'budgets'              => $budgets,
        ];
    }

    private function buildSystemPrompt(array $context): string
    {
        $budgetsText = empty($context['budgets'])
            ? 'Belum ada budget yang diatur bulan ini.'
            : collect($context['budgets'])
                ->map(fn($b) => "- {$b['category']}: Terpakai Rp " . number_format($b['spent'], 0, ',', '.') . " dari limit Rp " . number_format($b['limit'], 0, ',', '.'))
                ->join("\n");

        return <<<PROMPT
Kamu adalah MoneFin AI, penasihat keuangan pribadi cerdas dari aplikasi MoneFin.
Nama pengguna: {$context['name']}
Bulan ini: {$context['current_month']}
Total Pemasukan Bulan Ini: Rp {$context['total_income_month']}
Total Pengeluaran Bulan Ini: Rp {$context['total_expense_month']}
Tabungan Bersih Bulan Ini: Rp {$context['net_savings_month']}

Status Budget Bulan Ini:
{$budgetsText}

Tugasmu:
1. Berikan jawaban yang ramah, sopan, dan solutif seputar perencanaan keuangan, penghematan, dan analisis budget pengguna.
2. Gunakan angka riil keuangan pengguna di atas jika relevan untuk menjawab pertanyaannya.
3. Jawab dalam Bahasa Indonesia yang santun dan profesional.
PROMPT;
    }
}
EOF_SERVICE;

if (file_put_contents($aiServiceFile, $aiServiceCode)) {
    echo "[OK] AiService.php berhasil di-patch secara utuh.\n";
} else {
    echo "[FAIL] Gagal menulis AiService.php.\n";
}

// ── 4. Update AiController.php Secara Utuh ───
$ctrlFile = $base . '/app/Http/Controllers/AiController.php';
$ctrlCode = <<<'EOF_CONTROLLER'
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
            try {
                // Safely clean non-zlib buffers
                while (ob_get_level() > 0) {
                    $status = ob_get_status();
                    if (!empty($status['name']) && (str_contains($status['name'], 'zlib') || str_contains($status['name'], 'compress'))) {
                        break;
                    }
                    @ob_end_clean();
                }

                $this->ai->streamChat(
                    $user,
                    $validated['message'],
                    $validated['history'] ?? [],
                    function (string $token) {
                        echo "data: " . json_encode(['text' => $token]) . "\n\n";
                        if (ob_get_level() > 0) {
                            @ob_flush();
                        }
                        @flush();
                    }
                );

                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('AI Stream Error: ' . $e->getMessage());
                echo "data: " . json_encode(['text' => "Maaf, terjadi gangguan pada AI Chatbot: " . $e->getMessage()]) . "\n\n";
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            }
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
     * Purely deterministic — no AI required.
     */
    public function insights(Request $request): JsonResponse
    {
        $lang   = $request->header('Accept-Language') ?? ($request->user()?->preferences['language'] ?? 'id');
        $result = $this->healthService->insights($request->user(), $lang);
        return response()->json(['data' => $result]);
    }

    /**
     * GET / POST /api/ai/test-connection
     * Test the AI provider with a minimal message. Accepts credentials in request or falls back to saved preferences.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $user  = $request->user();
        $prefs = $user->preferences ?? [];

        $provider = $request->input('provider') ?: ($prefs['ai_config']['provider'] ?? '');
        $model    = $request->input('model')    ?: ($prefs['ai_config']['model'] ?? '');
        $baseUrl  = $request->input('base_url') ?: ($prefs['ai_config']['base_url'] ?? null);
        $rawKey   = $request->input('api_key');

        if (empty($rawKey)) {
            $rawKey = $this->ai->getDecryptedApiKey($user);
        }

        if (empty($provider) || empty($rawKey)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Pilih provider dan masukkan API key terlebih dahulu.',
            ], 422);
        }

        $result = $this->ai->testConnectionDirect($provider, $rawKey, $model, $baseUrl);

        return response()->json([
            'ok'       => $result['ok'],
            'provider' => $result['provider'] ?? $provider,
            'model'    => $result['model']    ?? $model,
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
            'ai_enabled'   => ['required', 'boolean'],
            'provider'     => ['nullable', 'string', 'in:' . implode(',', array_keys(AiProviderFactory::PROVIDERS))],
            'custom_name'  => ['nullable', 'string', 'max:100'],
            'model'        => ['nullable', 'string', 'max:255'],
            'api_key'      => ['nullable', 'string', 'max:500'],
            'base_url'     => ['nullable', 'string', 'max:500'],
        ]);

        $user  = $request->user();
        $prefs = $user->preferences ?? [];

        $prefs['ai_enabled'] = $validated['ai_enabled'];

        if (!empty($validated['provider'])) {
            $existing = $prefs['ai_config'] ?? [];

            $prefs['ai_config'] = [
                'provider'    => $validated['provider'],
                'custom_name' => !empty($validated['custom_name']) ? trim($validated['custom_name']) : ($existing['custom_name'] ?? null),
                'model'       => $validated['model'] ?? AiProviderFactory::defaultModel($validated['provider']),
                'base_url'    => !empty($validated['base_url']) ? trim($validated['base_url']) : ($existing['base_url'] ?? null),
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
                'provider'       => $prefs['ai_config']['provider']       ?? null,
                'custom_name'    => $prefs['ai_config']['custom_name']    ?? null,
                'model'          => $prefs['ai_config']['model']          ?? null,
                'base_url'       => $prefs['ai_config']['base_url']       ?? null,
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
EOF_CONTROLLER;

if (file_put_contents($ctrlFile, $ctrlCode)) {
    echo "[OK] AiController.php berhasil di-patch secara utuh.\n";
} else {
    echo "[FAIL] Gagal menulis AiController.php.\n";
}

// ── 5. Update UserApiKeyService.php Secara Utuh ───
$keyServiceFile = $base . '/app/Services/UserApiKeyService.php';
$keyServiceCode = <<<'EOF_KEYSERVICE'
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Service to securely manage Bring-Your-Own-Key (BYOK) encryption and decryption
 * with a high-throughput memory cache layer to eliminate redundant DB reads and AES crypto overhead.
 */
class UserApiKeyService
{
    public const CACHE_TTL_SECONDS = 900; // 15 Minutes

    /**
     * Store and encrypt a user API key.
     */
    public function storeKey(User $user, string $provider, string $rawKey, array $extraConfig = []): void
    {
        $encrypted = Crypt::encryptString($rawKey);

        $prefs = $user->preferences ?? [];
        $aiConfig = $prefs['ai_config'] ?? [];

        $aiConfig['provider'] = $provider;
        $aiConfig['api_key'] = $encrypted;
        foreach ($extraConfig as $k => $v) {
            $aiConfig[$k] = $v;
        }

        $prefs['ai_config'] = $aiConfig;
        $prefs['ai_enabled'] = true;
        $user->preferences = $prefs;
        $user->save();

        // Bust cache
        Cache::forget("user:{$user->id}:ai_key:{$provider}");
        Cache::forget("user:{$user->id}:ai_key:active");
    }

    /**
     * Retrieve decrypted API key with Cache layer (15 min TTL).
     */
    public function getDecryptedKey(User $user, ?string $provider = null): ?string
    {
        $prefs = $user->preferences ?? [];
        $aiConfig = $prefs['ai_config'] ?? [];
        $targetProvider = $provider ?: ($aiConfig['provider'] ?? 'openai');
        $encryptedKey = $aiConfig['api_key_encrypted'] ?? ($aiConfig['api_key'] ?? null);

        if (!$encryptedKey) {
            return null;
        }

        $cacheKey = "user:{$user->id}:ai_key:{$targetProvider}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($encryptedKey) {
            try {
                return Crypt::decryptString($encryptedKey);
            } catch (\Throwable $e) {
                Log::warning("Failed to decrypt user AI API key: {$e->getMessage()}");
                return null;
            }
        });
    }

    /**
     * Clear cached API key for a user.
     */
    public function clearKeyCache(User $user, ?string $provider = null): void
    {
        if ($provider) {
            Cache::forget("user:{$user->id}:ai_key:{$provider}");
        }
        Cache::forget("user:{$user->id}:ai_key:active");
        Cache::forget("user:{$user->id}:ai_key:openai");
        Cache::forget("user:{$user->id}:ai_key:claude");
        Cache::forget("user:{$user->id}:ai_key:openrouter");
        Cache::forget("user:{$user->id}:ai_key:gemini");
    }
}
EOF_KEYSERVICE;

if (file_put_contents($keyServiceFile, $keyServiceCode)) {
    echo "[OK] UserApiKeyService.php berhasil di-patch secara utuh.\n";
} else {
    echo "[FAIL] Gagal menulis UserApiKeyService.php.\n";
}

// ── 6. Update routes/api.php ───
$routesFile = $base . '/routes/api.php';
$routesContent = file_get_contents($routesFile);
if (strpos($routesContent, "Route::match(['get', 'post'], '/test-connection'") === false) {
    $routesContent = str_replace(
        "Route::get('/test-connection'",
        "Route::match(['get', 'post'], '/test-connection'",
        $routesContent
    );
    file_put_contents($routesFile, $routesContent);
    echo "[OK] routes/api.php berhasil di-patch (support GET & POST test-connection).\n";
} else {
    echo "[OK] routes/api.php sudah up-to-date.\n";
}

// ── 7. Update AppServiceProvider.php (Relax ai-connection-test rate limiter) ───
$providerFile = $base . '/app/Providers/AppServiceProvider.php';
$providerContent = file_get_contents($providerFile);
if (strpos($providerContent, 'Limit::perMinutes(10, 3)') !== false) {
    $providerContent = str_replace(
        "Limit::perMinutes(10, 3)->by('ai-test:' . \$request->user()->id)",
        "Limit::perMinute(30)->by('ai-test:' . \$request->user()->id)",
        $providerContent
    );
    file_put_contents($providerFile, $providerContent);
    echo "[OK] AppServiceProvider.php berhasil di-patch (rate limit dilonggarkan ke 30 req/menit).\n";
} else {
    echo "[OK] AppServiceProvider.php rate limit sudah up-to-date.\n";
}

// ── 8. Refresh Laravel Cache & Reset Rate Limiter ───
try {
    require $base . '/vendor/autoload.php';
    $app = require_once $base . '/bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    \Illuminate\Support\Facades\Artisan::call('route:clear');
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    \Illuminate\Support\Facades\Cache::flush();
    echo "\n[OK] Route, Config, dan Cache (Rate Limiter) berhasil dibersihkan & di-reset!\n";
} catch (\Throwable $e) {
    echo "\n[INFO] Artisan clear: " . $e->getMessage() . "\n";
}

echo "\n=== Patch Selesai! Silakan HAPUS file patch_ai_byok.php dari server! ===\n";
