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

    private string $model;

    public function __construct(
        private readonly string $provider,
        private readonly string $apiKey,
        string $model,
        ?string $customBaseUrl = null,
    ) {
        // Gemini: resolve via single source of truth (AiProviderFactory).
        // Live & custom models pass through untouched; only retired IDs
        // (gemini-2.0-flash, gemini-1.5-*) are upgraded — never downgraded.
        if ($provider === 'gemini') {
            $resolved = AiProviderFactory::resolveGeminiModel($model);
            if ($resolved !== $model) {
                Log::warning('AI Gemini model resolved', ['from' => $model, 'to' => $resolved]);
            }
            $model = $resolved;
        } else {
            // Groq/Grok: upgrade retired IDs (mixtral-8x7b-32768, grok-2-*) only.
            $resolved = AiProviderFactory::resolveOtherProviderModel($provider, $model);
            if ($resolved !== $model) {
                Log::warning('AI provider model resolved', ['provider' => $provider, 'from' => $model, 'to' => $resolved]);
            }
            $model = $resolved;
        }
        $this->model = $model;

        if ($provider === 'custom' && !empty($customBaseUrl)) {
            $validatedUrl = SsrfUrlValidator::validate($customBaseUrl);
            $url = rtrim($validatedUrl, '/');
            if (str_ends_with($url, '/chat/completions')) {
                $url = substr($url, 0, -strlen('/chat/completions'));
            }
            $this->baseUrl = $url;
        } else {
            $this->baseUrl = self::BASE_URLS[$provider] ?? 'https://api.openai.com/v1';
        }
    }

    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 4096): string
    {
        @set_time_limit(60);

        $verifySSL = (bool) config('services.ai.verify_ssl', false);
        $startedAt = microtime(true);

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];

        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => config('app.url', 'https://monefin.web.id'),
            'X-Title'       => 'MoneFin',
        ];

        try {
            $response = Http::timeout(25)
                ->withOptions(['verify' => $verifySSL])
                ->withHeaders($headers)
                ->post("{$this->baseUrl}/chat/completions", $payload);
        } catch (\Throwable $e) {
            // Check for timeout
            if (str_contains($e->getMessage(), 'cURL error 28') || str_contains($e->getMessage(), 'timed out')) {
                Log::warning('AI provider timeout', [
                    'provider'   => $this->provider,
                    'model'      => $this->model,
                    'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);
                return "Terjadi kesalahan: Model AI '{$this->model}' tidak merespons (timeout 25 detik). Silakan coba lagi, atau ganti model di Pengaturan → AI Chatbot.";
            }

            // Graceful fallback: If SSL certificate verification fails (cURL error 60), retry without SSL verification ONLY for standard known providers (never for untrusted custom endpoints)
            if ($this->provider !== 'custom' && (str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'SSL certificate'))) {
                try {
                    $response = Http::timeout(25)
                        ->withOptions(['verify' => false])
                        ->withHeaders($headers)
                        ->post("{$this->baseUrl}/chat/completions", $payload);
                } catch (\Throwable $retryEx) {
                    if (str_contains($retryEx->getMessage(), 'cURL error 28') || str_contains($retryEx->getMessage(), 'timed out')) {
                        return "Model AI '{$this->model}' tidak merespons (timeout 25 detik). Antrean provider sedang padat. Silakan coba lagi.";
                    }
                    Log::error('AI provider retry exception', [
                        'provider' => $this->provider,
                        'message'  => $retryEx->getMessage(),
                    ]);
                    return 'Terjadi kesalahan saat menghubungi AI (' . $retryEx->getMessage() . '). Periksa Base URL dan koneksi.';
                }
            } else {
                Log::error('AI provider exception', [
                    'provider' => $this->provider,
                    'message'  => $e->getMessage(),
                ]);
                return 'Terjadi kesalahan saat menghubungi AI (' . $e->getMessage() . '). Periksa Base URL dan koneksi.';
            }
        }

        if ($response->failed()) {
            // Jika Google Gemini 503 (model overloaded / transient), coba otomatis fallback
            // SATU level ke model hidup (bukan model pensiun), maksimal 1x.
            $geminiFallback = AiProviderFactory::GEMINI_FALLBACK_MODEL;
            if ($this->provider === 'gemini' && $response->status() === 503 && $this->model !== $geminiFallback) {
                $fallbackPayload = $payload;
                $fallbackPayload['model'] = $geminiFallback;
                try {
                    $fallbackRes = Http::timeout(25)
                        ->withOptions(['verify' => $verifySSL])
                        ->withHeaders($headers)
                        ->post("{$this->baseUrl}/chat/completions", $fallbackPayload);
                    if ($fallbackRes->successful()) {
                        $fbData = $fallbackRes->json();
                        $fbContent = $fbData['choices'][0]['message']['content'] ?? '';
                        if (!empty($fbContent)) {
                            Log::info('AI Gemini 503 fallback success', [
                                'from'       => $this->model,
                                'to'         => $geminiFallback,
                                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                            ]);
                            return trim(preg_replace('/<think>.*?<\/think>/s', '', $fbContent));
                        }
                    }
                } catch (\Throwable) {
                    // Lanjut ke error handler standar
                }
            }

            $body = $response->json();
            Log::warning('AI provider error', [
                'provider'   => $this->provider,
                'model'      => $this->model,
                'status'     => $response->status(),
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'body'       => substr($response->body(), 0, 500),
            ]);

            return $this->handleError($body, $response->status());
        }

        $data = $response->json();
        Log::info('AI chat success', [
            'provider'   => $this->provider,
            'model'      => $this->model,
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);
        $rawContent = $data['choices'][0]['message']['content']
            ?? 'Maaf, saya tidak mendapatkan respons yang valid dari AI. Silakan coba lagi.';
        return trim(preg_replace('/<think>.*?<\/think>/s', '', $rawContent));
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
            if ($this->provider === 'gemini') {
                return "Model '{$this->model}' tidak ditemukan di Google Gemini (404). Model ini kemungkinan sudah pensiun — pilih model 3.x yang masih aktif di Pengaturan → AI Chatbot.";
            }
            return "Endpoint {$this->providerLabel()} tidak ditemukan (404 Not Found). Periksa Base URL ({$this->baseUrl}).";
        }

        if ($status === 429) {
            return $this->quotaExhaustedMessage();
        }

        return "Maaf, {$this->providerLabel()} sedang tidak tersedia (HTTP {$status}). Silakan coba beberapa saat lagi.";
    }

    public function streamChat(array $messages, callable $onChunk, float $temperature = 0.7): void
    {
        $verifySSL = (bool) config('services.ai.verify_ssl', false);
        $startedAt = microtime(true);
        // Budget: total stream ≤ 90 dtk agar di bawah batas Cloudflare (~100 dtk)
        // dan max_execution_time shared hosting.

        $postStream = function (bool $verify) use ($messages, $temperature) {
            $client = new \GuzzleHttp\Client([
                'timeout'      => 90.0,
                'read_timeout' => 60.0,
                'verify'       => $verify,
                'http_errors'  => false,
            ]);

            return $client->post("{$this->baseUrl}/chat/completions", [
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
                    'max_tokens'  => 4096,
                    'stream'      => true,
                ],
                'stream' => true,
            ]);
        };

        try {
            try {
                $response = $postStream($verifySSL);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'SSL certificate')) {
                    $response = $postStream(false);
                } else {
                    throw $e;
                }
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                // Jika Google Gemini 503 (model overloaded / transient), coba otomatis fallback
                // SATU level ke model hidup (bukan model pensiun), maksimal 1x.
                $geminiFallback = AiProviderFactory::GEMINI_FALLBACK_MODEL;
                if ($this->provider === 'gemini' && $statusCode === 503 && $this->model !== $geminiFallback) {
                    try {
                        $fallbackClient = new \GuzzleHttp\Client([
                            'timeout'      => 90.0,
                            'read_timeout' => 60.0,
                            'verify'       => $verifySSL,
                            'http_errors'  => false,
                        ]);
                        $fallbackResponse = $fallbackClient->post("{$this->baseUrl}/chat/completions", [
                            'headers' => [
                                'Authorization' => "Bearer {$this->apiKey}",
                                'Content-Type'  => 'application/json',
                                'Accept'        => 'text/event-stream',
                                'HTTP-Referer'  => config('app.url', 'https://monefin.web.id'),
                                'X-Title'       => 'MoneFin',
                            ],
                            'json' => [
                                'model'       => $geminiFallback,
                                'messages'    => $messages,
                                'temperature' => $temperature,
                                'max_tokens'  => 4096,
                                'stream'      => true,
                            ],
                            'stream' => true,
                        ]);

                        if ($fallbackResponse->getStatusCode() < 400) {
                            Log::info('AI Gemini stream 503 fallback success', [
                                'from' => $this->model,
                                'to'   => $geminiFallback,
                            ]);
                            $response = $fallbackResponse;
                            $statusCode = $response->getStatusCode();
                        }
                    } catch (\Throwable) {
                        // Tetap gunakan response asli jika fallback gagal
                    }
                }
            }

            if ($statusCode >= 400) {
                $rawBody = (string) $response->getBody();
                $errJson = json_decode($rawBody, true);
                Log::warning('AI stream HTTP error', [
                    'provider'   => $this->provider,
                    'model'      => $this->model,
                    'status'     => $statusCode,
                    'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'body'       => substr($rawBody, 0, 500),
                ]);
                // Prefix ERROR: agar frontend bisa membedakan error vs token jawaban.
                if ($statusCode === 503 && $this->provider === 'gemini') {
                    $onChunk("ERROR:503| Layanan Google Gemini sedang sibuk / overloaded (503). Tunggu beberapa detik lalu tekan Coba Lagi, atau pilih model lain di Pengaturan → AI Chatbot.");
                } else {
                    $errMsg = $errJson['error']['message'] ?? "Error {$statusCode} dari AI provider.";
                    $onChunk("ERROR:{$statusCode}| Error dari {$this->providerLabel()} ({$statusCode}): {$errMsg}");
                }
                return;
            }

            $body = $response->getBody();
            $buffer = '';
            $inThink = false;

            $processSseLine = function (string $line) use (&$inThink, $onChunk, $startedAt): bool {
                if (!str_starts_with($line, 'data:')) {
                    return false;
                }
                $data = trim(substr($line, 5));
                if ($data === '') {
                    return false;
                }
                if ($data === '[DONE]') {
                    Log::info('AI stream success', [
                        'provider'   => $this->provider,
                        'model'      => $this->model,
                        'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    ]);
                    return true;
                }
                $json = json_decode($data, true);
                $token = $json['choices'][0]['delta']['content'] ?? '';
                if ($token !== '') {
                    // Filter out <think> ... </think> reasoning tokens from streaming
                    if (str_contains($token, '<think>')) {
                        $before = substr($token, 0, strpos($token, '<think>'));
                        if ($before !== '') {
                            $onChunk($before);
                        }
                        $token = '';
                        $inThink = true;
                    }
                    if ($inThink && $token !== '') {
                        if (str_contains($token, '</think>')) {
                            $inThink = false;
                            $after = substr($token, strpos($token, '</think>') + 8);
                            $token = $after;
                        } else {
                            $token = '';
                        }
                    }
                    if (!$inThink && $token !== '') {
                        $onChunk($token);
                    }
                }
                return false;
            };

            while (!$body->eof()) {
                $chunk = $body->read(1024);
                if ($chunk === '') {
                    usleep(5000);
                    continue;
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($processSseLine($line)) {
                        return;
                    }
                }
            }

            if (trim($buffer) !== '') {
                foreach (explode("\n", $buffer) as $remLine) {
                    if ($processSseLine(trim($remLine))) {
                        return;
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