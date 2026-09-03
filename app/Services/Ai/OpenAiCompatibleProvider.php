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
    ) {
        $this->baseUrl = self::BASE_URLS[$provider] ?? 'https://api.openai.com/v1';
    }

    public function chat(array $messages, float $temperature = 0.7): string
    {
        $verifySSL = config('app.env') === 'production';

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
                    'max_tokens'  => 4096,
                ]);

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
            return 'Terjadi kesalahan saat menghubungi AI. Periksa koneksi internet atau coba lagi.';
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

        if ($status === 401) {
            return "API key {$this->providerLabel()} tidak valid atau sudah expired. Silakan perbarui API key di Settings → AI Chatbot.";
        }

        if ($status === 429) {
            return $this->quotaExhaustedMessage();
        }

        if (!empty($errorMessage)) {
            return "Error dari {$this->providerLabel()}: {$errorMessage}";
        }

        return "Maaf, {$this->providerLabel()} sedang tidak tersedia. Silakan coba beberapa saat lagi.";
    }

    public function streamChat(array $messages, callable $onChunk, float $temperature = 0.7): void
    {
        $verifySSL = config('app.env') === 'production';

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 90.0,
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
                    'max_tokens'  => 4096,
                    'stream'      => true,
                ],
                'stream' => true,
            ]);

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
        $labels = [
            'openai'   => 'platform.openai.com/account/billing',
            'gemini'   => 'aistudio.google.com',
            'deepseek' => 'platform.deepseek.com',
            'kimi'     => 'platform.moonshot.cn',
            'groq'     => 'console.groq.com',
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
            'groq'     => 'Groq',
            default    => ucfirst($this->provider),
        };
    }
}
