<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Universal provider for OpenAI-compatible APIs.
 * Covers: OpenAI, Gemini (OpenAI-compat), DeepSeek, Kimi/Moonshot, Groq.
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
                $chunk = $body->read(64);
                if ($chunk === '') {
                    usleep(5000);
                    continue;
                }
                $buffer .= $chunk;
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
