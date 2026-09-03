<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Claude provider.
 * Uses the Messages API which differs from the OpenAI format.
 */
class ClaudeProvider implements AiProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function chat(array $messages, float $temperature = 0.7): string
    {
        // Claude requires system messages to be separated from user/assistant turns
        $systemContent = null;
        $turns = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemContent = $msg['content'];
            } else {
                $turns[] = [
                    'role'    => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => $msg['content'],
                ];
            }
        }

        // Claude requires alternating user/assistant turns, starting with user
        if (empty($turns)) {
            return 'Tidak ada pesan yang valid untuk dikirim ke Claude.';
        }

        $verifySSL = config('app.env') === 'production';

        $payload = [
            'model'       => $this->model,
            'max_tokens'  => 4096,
            'temperature' => $temperature,
            'messages'    => $turns,
        ];

        if ($systemContent) {
            $payload['system'] = $systemContent;
        }

        try {
            $response = Http::timeout(60)
                ->withOptions(['verify' => $verifySSL])
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type'      => 'application/json',
                ])
                ->post(self::API_URL, $payload);

            if ($response->failed()) {
                $body = $response->json();
                Log::warning('Claude API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return $this->handleError($body, $response->status());
            }

            $data = $response->json();
            return $data['content'][0]['text']
                ?? 'Maaf, saya tidak mendapatkan respons yang valid dari Claude. Silakan coba lagi.';

        } catch (\Throwable $e) {
            Log::error('Claude API exception', ['message' => $e->getMessage()]);
            return 'Terjadi kesalahan saat menghubungi Claude. Periksa koneksi internet atau coba lagi.';
        }
    }

    public function streamChat(array $messages, callable $onChunk, float $temperature = 0.7): void
    {
        $systemPrompt = '';
        $userMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt .= ($systemPrompt ? "\n\n" : '') . $msg['content'];
            } else {
                $userMessages[] = [
                    'role'    => $msg['role'],
                    'content' => $msg['content'],
                ];
            }
        }

        if (empty($userMessages)) {
            $userMessages[] = ['role' => 'user', 'content' => 'Halo'];
        }

        $payload = [
            'model'       => $this->model,
            'max_tokens'  => 4096,
            'messages'    => $userMessages,
            'temperature' => $temperature,
            'stream'      => true,
        ];

        if (!empty($systemPrompt)) {
            $payload['system'] = $systemPrompt;
        }

        $verifySSL = config('app.env') === 'production';

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 90.0,
                'verify'  => $verifySSL,
            ]);

            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                    'accept'            => 'text/event-stream',
                ],
                'json'   => $payload,
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
                        $json = json_decode($data, true);
                        if (isset($json['type']) && $json['type'] === 'content_block_delta') {
                            $token = $json['delta']['text'] ?? '';
                            if ($token !== '') {
                                $onChunk($token);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Claude stream error', ['message' => $e->getMessage()]);
            $onChunk("\n[Terjadi gangguan koneksi saat streaming: " . $e->getMessage() . "]");
        }
    }

    public function getProviderName(): string
    {
        return 'claude';
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

        $combined = strtolower($errorType . ' ' . $errorMessage);

        if (
            str_contains($combined, 'credit_balance_too_low') ||
            str_contains($combined, 'insufficient') ||
            str_contains($combined, 'quota') ||
            $status === 429
        ) {
            return 'QUOTA_EXCEEDED|Anthropic Claude|console.anthropic.com';
        }

        if ($status === 401) {
            return 'API key Anthropic Claude tidak valid. Silakan perbarui di Settings → AI Chatbot.';
        }

        if (!empty($errorMessage)) {
            return "Error dari Anthropic Claude: {$errorMessage}";
        }

        return 'Maaf, Anthropic Claude sedang tidak tersedia. Silakan coba beberapa saat lagi.';
    }
}
