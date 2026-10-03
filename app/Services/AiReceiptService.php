<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use App\Services\Ai\AiProviderFactory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiReceiptService
{
    public function __construct(
        private readonly UserApiKeyService $keyService = new UserApiKeyService(),
    ) {}

    /**
     * Scan and parse 1 to 8 receipt images using user's BYOK Vision LLM.
     *
     * @param  User         $user
     * @param  array|string $imagesInput Array of ['base64' => string, 'mime_type' => string] or raw base64 string
     * @param  string       $mimeType    Fallback mime type when $imagesInput is a string
     * @return array
     */
    public function scanReceipt(User $user, array|string $imagesInput, string $mimeType = 'image/jpeg'): array
    {
        @set_time_limit(120);

        $prefs    = $user->preferences ?? [];
        $aiConfig = $prefs['ai_config'] ?? [];
        $provider = $aiConfig['provider'] ?? '';
        $encKey   = $aiConfig['api_key_encrypted'] ?? ($aiConfig['api_key'] ?? '');

        if (!$provider || !$encKey) {
            return [
                'success' => false,
                'code'    => 'NO_AI_KEY',
                'message' => 'Kunci API (BYOK) belum dikonfigurasi. Hubungkan API key Anda (seperti Google Gemini gratis) di menu Pengaturan → AI Chatbot untuk menggunakan fitur Pindai Struk.',
            ];
        }

        $apiKey = $this->keyService->getDecryptedKey($user, $provider);
        if (!$apiKey) {
            try {
                $apiKey = Crypt::decryptString($encKey);
            } catch (\Throwable) {
                return [
                    'success' => false,
                    'code'    => 'DECRYPT_FAILED',
                    'message' => 'Gagal membaca API key. Silakan simpan ulang API key Anda di Pengaturan → AI Chatbot.',
                ];
            }
        }

        // Check if provider supports Vision
        if ($provider === 'deepseek') {
            return [
                'success' => false,
                'code'    => 'VISION_NOT_SUPPORTED',
                'message' => 'Provider DeepSeek saat ini belum mendukung analisis gambar (vision). Silakan gunakan Google Gemini (tersedia gratis), OpenAI, atau Claude di Pengaturan AI.',
            ];
        }

        // Normalize input into array of ['base64' => ..., 'mime_type' => ...] (1..8 images)
        $images = [];
        if (is_string($imagesInput)) {
            if ($imagesInput !== '') {
                $images[] = [
                    'base64'    => $imagesInput,
                    'mime_type' => $mimeType ?: 'image/jpeg',
                ];
            }
        } else {
            foreach (array_slice($imagesInput, 0, 8) as $entry) {
                if (is_array($entry) && !empty($entry['base64'])) {
                    $images[] = [
                        'base64'    => (string) $entry['base64'],
                        'mime_type' => !empty($entry['mime_type']) ? (string) $entry['mime_type'] : ($mimeType ?: 'image/jpeg'),
                    ];
                } elseif (is_string($entry) && $entry !== '') {
                    $images[] = [
                        'base64'    => $entry,
                        'mime_type' => $mimeType ?: 'image/jpeg',
                    ];
                }
            }
        }

        if (empty($images)) {
            return [
                'success' => false,
                'code'    => 'NO_IMAGE',
                'message' => 'Tidak ada foto struk yang dapat diproses.',
            ];
        }

        $userModel = $aiConfig['model'] ?? '';
        $baseUrl   = $aiConfig['base_url'] ?? null;

        // Pagar ukuran payload: per gambar > ~2.8M char (≈2.1MB biner) atau total gabungan > 8M char (≈6MB biner)
        // ditolak cepat agar PHP shared hosting tidak tersumbat sebelum memanggil provider.
        $totalBase64Len = 0;
        foreach ($images as $idx => $img) {
            $len = strlen($img['base64']);
            $totalBase64Len += $len;
            if ($len > 2800000) {
                $num = $idx + 1;
                return [
                    'success' => false,
                    'code'    => 'IMAGE_TOO_LARGE',
                    'message' => "Foto struk #{$num} terlalu besar untuk diproses cepat. Ulangi dengan foto yang sudah dikompresi otomatis oleh aplikasi.",
                ];
            }
        }

        if ($totalBase64Len > 8000000) {
            return [
                'success' => false,
                'code'    => 'IMAGE_TOO_LARGE',
                'message' => 'Total ukuran gabungan foto struk terlalu besar. Kurangi jumlah foto atau gunakan kompresi otomatis aplikasi.',
            ];
        }

        $imageCount = count($images);
        $prompt     = $this->buildReceiptPrompt($imageCount);

        try {
            $rawJson = $this->callVisionProvider($provider, $apiKey, $userModel, $baseUrl, $prompt, $images);
        } catch (\Throwable $e) {
            Log::error('AiReceiptService callVisionProvider error', [
                'provider'    => $provider,
                'image_count' => $imageCount,
                'error'       => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'code'    => 'AI_PROVIDER_ERROR',
                'message' => 'Gagal menganalisis struk: ' . $e->getMessage(),
            ];
        }

        if (str_starts_with($rawJson, 'QUOTA_EXCEEDED|')) {
            $parts = explode('|', $rawJson);
            $provName = $parts[1] ?? ucfirst($provider);
            return [
                'success' => false,
                'code'    => 'QUOTA_EXCEEDED',
                'message' => "Batas request atau kuota API {$provName} Anda sedang penuh / habis (Rate Limit / Quota Exceeded). Jika menggunakan model gratisan (seperti di OpenRouter), silakan tunggu 10–20 detik lalu coba lagi, atau ganti ke Google Gemini (gratis dengan kuota lebih besar & cepat) di menu Pengaturan → AI Chatbot.",
            ];
        }

        $parsed = $this->parseAndValidateJson($rawJson, $imageCount);

        if (!$parsed) {
            return [
                'success' => false,
                'code'    => 'PARSE_FAILED',
                'message' => 'AI berhasil memproses gambar, tetapi format data struk tidak terbaca sempurna. Pastikan foto struk terlihat jelas, terang, dan tidak terpotong.',
                'raw'     => $rawJson,
            ];
        }

        // Match categories with user's existing categories
        $matchedCategory = $this->matchCategory($user->id, $parsed['suggested_category'] ?? '', $parsed['merchant'] ?? '');
        $parsed['suggested_category_id']   = $matchedCategory['id'] ?? null;
        $parsed['suggested_category_name'] = $matchedCategory['name'] ?? ($parsed['suggested_category'] ?? 'Lain-lain');

        // Match categories for each item
        if (!empty($parsed['items']) && is_array($parsed['items'])) {
            foreach ($parsed['items'] as &$item) {
                $itemCat = $this->matchCategory($user->id, $item['category'] ?? '', $item['name'] ?? '');
                $item['category_id']   = $itemCat['id'] ?? $parsed['suggested_category_id'];
                $item['category_name'] = $itemCat['name'] ?? ($item['category'] ?? $parsed['suggested_category_name']);
            }
            unset($item);
        }

        return [
            'success' => true,
            'data'    => $parsed,
        ];
    }

    /**
     * Dispatch multimodal request (with 1..8 images) to the appropriate vision provider.
     *
     * @param array<int, array{base64: string, mime_type: string}> $images
     */
    private function callVisionProvider(
        string $provider,
        string $apiKey,
        string $model,
        ?string $baseUrl,
        string $prompt,
        array $images
    ): string {
        $verifySSL  = (bool) config('services.ai.verify_ssl', false);
        $imageCount = count($images);
        $timeoutSec = $imageCount > 1 ? 40 : 28;

        // 1. Google Gemini Native API — model via single source of truth.
        // Model hidup & custom user diteruskan apa adanya; hanya ID pensiun
        // (2.0 / 1.5-*) yang di-upgrade. gemini-2.5-flash masih hidup (s.d. Okt 2026).
        if ($provider === 'gemini') {
            $geminiModel = AiProviderFactory::resolveGeminiModel($model);

            $parts = [['text' => $prompt]];
            foreach ($images as $img) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $img['mime_type'],
                        'data'      => $img['base64'],
                    ],
                ];
            }

            $nativePayload = [
                'contents' => [
                    [
                        'parts' => $parts,
                    ],
                ],
                'generationConfig' => [
                    'temperature'      => 0.1,
                    'responseMimeType' => 'application/json',
                ],
            ];

            // Kandidat: model user + SATU fallback hidup. Fail-fast: pindah versi API
            // (v1beta→v1) hanya saat 404; error lain (429/503/400) langsung ke model berikut.
            $candidateModels = array_values(array_unique([$geminiModel, AiProviderFactory::GEMINI_FALLBACK_MODEL]));
            $apiVersions     = ['v1beta', 'v1'];
            $nativeRes       = null;
            $nativeStartedAt = microtime(true);

            foreach ($candidateModels as $currentModel) {
                foreach ($apiVersions as $apiVersion) {
                    $endpoint = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$currentModel}:generateContent?key={$apiKey}";
                    try {
                        $attempt = Http::timeout($timeoutSec)
                            ->withOptions(['verify' => $verifySSL])
                            ->post($endpoint, $nativePayload);
                    } catch (\Throwable $e) {
                        Log::warning("Gemini {$apiVersion} request to {$currentModel} failed: " . $e->getMessage());
                        continue;
                    }

                    if ($attempt->successful()) {
                        Log::info('Gemini native receipt scan success', [
                            'model'       => $currentModel,
                            'version'     => $apiVersion,
                            'image_count' => $imageCount,
                            'latency_ms'  => (int) ((microtime(true) - $nativeStartedAt) * 1000),
                        ]);
                        $nativeRes = $attempt;
                        break 2;
                    }

                    $status = $attempt->status();
                    Log::warning("Gemini {$apiVersion} model {$currentModel} returned {$status}", [
                        'status' => $status,
                        'body'   => substr($attempt->body(), 0, 200),
                    ]);
                    $nativeRes = $attempt;
                    if ($status !== 404) {
                        break; // Bukan soal versi API — langsung coba model fallback
                    }
                }
            }

            if ($nativeRes && $nativeRes->successful()) {
                $data    = $nativeRes->json();
                $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($content) {
                    return $content;
                }
            }

            if ($nativeRes && $nativeRes->status() === 429) {
                return 'QUOTA_EXCEEDED|Google Gemini|ai.google.dev';
            }

            // Fall through to OpenAI-compatible Gemini endpoint if native API fails
            if (!$nativeRes || $nativeRes->status() === 404) {
                Log::info("Gemini native API 404 for model '{$geminiModel}', falling through to OpenAI-compatible endpoint.");
            } else {
                Log::warning('Gemini Native API returned error, falling through to OpenAI-compatible endpoint', [
                    'status' => $nativeRes->status(),
                    'body'   => $nativeRes->body(),
                ]);
            }
        }

        // 2. Anthropic Claude Provider
        if ($provider === 'claude') {
            $claudeModel = !empty($model) ? $model : 'claude-sonnet-5';
            $endpoint    = 'https://api.anthropic.com/v1/messages';

            $claudeContent = [];
            foreach ($images as $img) {
                $claudeContent[] = [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $img['mime_type'],
                        'data'       => $img['base64'],
                    ],
                ];
            }
            $claudeContent[] = [
                'type' => 'text',
                'text' => $prompt,
            ];

            $payload = [
                'model'       => $claudeModel,
                'max_tokens'  => 4096,
                'temperature' => 0.1,
                'messages'    => [
                    [
                        'role'    => 'user',
                        'content' => $claudeContent,
                    ],
                ],
            ];

            $res = Http::timeout($timeoutSec)
                ->withOptions(['verify' => $verifySSL])
                ->withHeaders([
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type'      => 'application/json',
                ])
                ->post($endpoint, $payload);

            if ($res->failed()) {
                if ($res->status() === 429) {
                    return 'QUOTA_EXCEEDED|Anthropic Claude|console.anthropic.com';
                }
                throw new \RuntimeException('Claude API error: ' . ($res->json()['error']['message'] ?? $res->body()));
            }

            return $res->json()['content'][0]['text'] ?? '';
        }

        // 3. OpenAI or OpenAI-Compatible Vision (GPT-4o, Groq, OpenRouter, Custom)
        $visionModel = $model;
        $openAiImages = $images;

        if ($provider === 'openai') {
            $live = ['gpt-5', 'gpt-5-mini', 'gpt-5.6-terra', 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo'];
            $visionModel = in_array($model, $live, true) ? $model : 'gpt-4o-mini';
            $baseEndpoint = 'https://api.openai.com/v1';
        } elseif ($provider === 'groq') {
            $visionModel = 'meta-llama/llama-4-scout-17b-16e-instruct';
            $baseEndpoint = 'https://api.groq.com/openai/v1';
            // Groq Llama-4 Scout supports max 5 images per request
            $openAiImages = array_slice($images, 0, 5);
        } elseif ($provider === 'gemini') {
            $visionModel = AiProviderFactory::resolveGeminiModel($model);
            $baseEndpoint = 'https://generativelanguage.googleapis.com/v1beta/openai';
        } else {
            $baseEndpoint = rtrim($baseUrl ?: 'https://api.openai.com/v1', '/');
            if (str_ends_with($baseEndpoint, '/chat/completions')) {
                $baseEndpoint = substr($baseEndpoint, 0, -strlen('/chat/completions'));
            }
        }

        $openAiContent = [
            [
                'type' => 'text',
                'text' => $prompt,
            ],
        ];
        foreach ($openAiImages as $img) {
            $openAiContent[] = [
                'type'      => 'image_url',
                'image_url' => [
                    'url' => "data:{$img['mime_type']};base64,{$img['base64']}",
                ],
            ];
        }

        $payload = [
            'model'       => $visionModel,
            'temperature' => 0.1,
            'max_tokens'  => 4096,
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => $openAiContent,
                ],
            ],
        ];

        $postVision = function (bool $verify) use ($baseEndpoint, $apiKey, $payload, $timeoutSec) {
            return Http::timeout($timeoutSec)
                ->withOptions(['verify' => $verify])
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => config('app.url', 'https://monefin.web.id'),
                    'X-Title'       => 'MoneFin',
                ])
                ->post("{$baseEndpoint}/chat/completions", $payload);
        };

        $visionStartedAt = microtime(true);
        try {
            $res = $postVision($verifySSL);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'cURL error 28') || str_contains($e->getMessage(), 'timed out')) {
                throw new \RuntimeException(
                    "Model AI '{$visionModel}' tidak merespons (timeout {$timeoutSec} detik). " .
                    "Antrean server sedang penuh atau model tidak aktif. " .
                    "Rekomendasi: gunakan Google Gemini langsung (gratis, paling cepat & stabil) dengan mengganti Provider ke 'Google Gemini' " .
                    "di Pengaturan → AI Chatbot."
                );
            }

            if (str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'SSL certificate')) {
                try {
                    $res = $postVision(false);
                } catch (\Throwable $retryEx) {
                    if (str_contains($retryEx->getMessage(), 'cURL error 28') || str_contains($retryEx->getMessage(), 'timed out')) {
                        throw new \RuntimeException(
                            "Model AI '{$visionModel}' tidak merespons (timeout {$timeoutSec} detik). " .
                            "Antrean server sedang penuh. Ganti ke Google Gemini di Pengaturan → AI Chatbot."
                        );
                    }
                    throw $retryEx;
                }
            } else {
                throw $e;
            }
        }

        // Satu retry SEGERA (tanpa sleep — shared hosting) untuk 429 transient.
        if ($res->status() === 429) {
            Log::info('AiReceiptService rate-limited (429), retrying once immediately...', [
                'provider' => $provider,
                'model'    => $visionModel,
            ]);
            try {
                $res = $postVision($verifySSL);
            } catch (\Throwable) {
                // Keep original 429 response if retry throws
            }
        }

        if ($res->failed()) {
            Log::warning('AiReceiptService callVisionProvider failed', [
                'provider' => $provider,
                'model'    => $visionModel,
                'status'   => $res->status(),
                'body'     => $res->body(),
            ]);

            if ($res->status() === 429) {
                return "QUOTA_EXCEEDED|" . ucfirst($provider) . "|dashboard provider Anda";
            }
            if ($res->status() === 503 || str_contains($res->body(), 'high demand') || str_contains($res->body(), 'UNAVAILABLE')) {
                throw new \RuntimeException(
                    "Server Google AI sedang mengalami lonjakan antrean sesaat (503 Service High Demand). " .
                    "Spike ini biasanya hanya berlangsung beberapa detik. Silakan coba klik tombol Ambil Foto / Pilih Galeri kembali."
                );
            }
            $errBody = $res->json();
            $errMsg  = $errBody['error']['message'] ?? $res->body();

            // OpenRouter: model yang dipilih tidak support vision/image input
            if (
                str_contains($errMsg, 'No endpoints found that support image input') ||
                str_contains($errMsg, 'image_url') ||
                str_contains($errMsg, 'does not support vision') ||
                str_contains($errMsg, 'multimodal')
            ) {
                throw new \RuntimeException(
                    "Model \"{$visionModel}\" tidak mendukung analisis gambar (bukan Vision model). " .
                    "Untuk scan struk, gunakan model yang memiliki kemampuan Vision, contoh: " .
                    "inclusionai/ling-3.0-flash-vl:free (gratis & stabil). " .
                    "Ganti model di Pengaturan → AI Chatbot."
                );
            }

            throw new \RuntimeException('Vision API error: ' . $errMsg);

        }

        Log::info('AiReceiptService vision scan success', [
            'provider'    => $provider,
            'model'       => $visionModel,
            'image_count' => $imageCount,
            'latency_ms'  => (int) ((microtime(true) - $visionStartedAt) * 1000),
        ]);

        return $res->json()['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Build strict, comprehensive system prompt for Indonesian receipt extraction
     * supporting both single-image and multi-image (long receipt or multi-receipt bundle).
     */
    private function buildReceiptPrompt(int $imageCount = 1): string
    {
        $today = Carbon::now()->toDateString();

        $multiImageInstruction = '';
        if ($imageCount > 1) {
            $multiImageInstruction = "\nKONTEKS MULTI-FOTO ({$imageCount} GAMBAR DITERIMA SECARA BERURUTAN DARI FOTO #1 SAMPAI FOTO #{$imageCount}):
Analisis terlebih dahulu hubungan antar {$imageCount} foto struk ini:
- KASUS A: STRUK PANJANG BERKELANJUTAN ('scan_type': 'long_receipt')
  Jika foto #1 sampai #{$imageCount} adalah 1 struk belanja fisik yang panjang dan difoto bertahap dari atas ke bawah:
  1. Lakukan Cross-Image Overlap Deduplication: jika ada 1-3 baris item yang sama persis di batas bawah Foto ke-N yang terfoto ulang di batas atas Foto ke-(N+1), HANYA catat satu kali (jangan diduplikasi). Namun jika barang yang sama memang dibeli berulang kali pada posisi struk yang berbeda, tetap catat sesuai struk.
  2. Ambil nilai 'subtotal', 'tax', 'discount', dan 'total' dari bagian paling bawah struk (Grand Total akhir), BUKAN menjumlahkan subtotal parsial.
- KASUS B: GABUNGAN BEBERAPA STRUK BERBEDA ('scan_type': 'multi_receipt')
  Jika foto-foto tersebut adalah beberapa struk belanja berbeda yang ingin digabung dalam 1 pencatatan transaksi:
  1. Gabungkan seluruh daftar 'items' dari semua struk secara berurutan.
  2. Jumlahkan nilai 'subtotal', 'tax', 'discount', dan 'total' dari seluruh struk tersebut.
  3. Untuk 'merchant', sebutkan gabungan nama toko secara ringkas (misal: 'Indomaret & Alfamart' atau 'Toko A + 2 Struk Lainnya').\n";
        }

        $defaultScanType = $imageCount > 1 ? 'long_receipt' : 'single';

        return "Kamu adalah sistem Optical Document Extraction khusus struk belanja untuk aplikasi keuangan MoneFin (Indonesia).
Tugasmu: Ekstrak seluruh informasi finansial dari foto struk belanja berikut dengan presisi tinggi.{$multiImageInstruction}
ATURAN EKSTRAKSI:
1. 'merchant': Nama toko/merchant/restoran (contoh: 'Indomaret', 'Alfamart', 'Superindo', 'Kopi Kenangan', 'SPBU Pertamina', 'McDonalds'). Jika tidak ada, tulis 'Toko Belanja'.
2. 'date': Tanggal transaksi dalam format 'YYYY-MM-DD'. Tahun saat ini adalah 2026 (hari ini: '{$today}'). Jika tanggal di struk menampilkan 2 digit tahun seperti 'DD/MM/YY' atau 'DD-MM-YY' (misal: '14/09/16' atau '14/09/26'), pastikan tahun yang dihasilkan adalah 2026 ('2026-09-14'), BUKAN 2016. Jika tanggal tidak terbaca, gunakan tanggal hari ini: '{$today}'.
3. 'currency': Mata uang (umumnya 'IDR').
4. 'scan_type': Jenis pemindaian, bernilai 'single' (jika 1 foto), 'long_receipt' (jika beberapa foto dari 1 struk panjang yang sama), atau 'multi_receipt' (jika beberapa foto dari struk-struk berbeda yang digabung).
5. 'items': Daftar item barang/menu yang dibeli. Setiap item memiliki:
   - 'name': Nama barang (singkatan struk mohon dirapikan bila mudah dikenali, misal 'ULTRA TPK 250ML' -> 'Ultra Milk 250ml').
   - 'qty': Jumlah kuantitas (angka integer/float, default 1).
   - 'price': Harga satuan dalam angka tanpa titik/koma (misal 15000).
   - 'total': Total harga item tersebut (qty * price).
   - 'category': Kategori perkiraan untuk item tersebut (contoh: 'Makanan & Minuman', 'Belanja & Kebutuhan', 'Kesehatan', 'Transportasi', 'Tagihan & Utilitas', 'Hiburan', 'Lain-lain').
6. 'subtotal': Total belanja sebelum pajak dan diskon.
7. 'tax': Nilai PPN/pajak jika ada (angka murni, default 0).
8. 'discount': Nilai diskon/potongan harga jika ada (angka murni positif, default 0).
9. 'total': TOTAL AKHIR yang benar-benar dibayarkan oleh pelanggan (setelah pajak dan diskon).
10. 'suggested_category': Kategori utama keseluruhan transaksi (pilih yang paling cocok: 'Makanan & Minuman', 'Belanja & Kebutuhan', 'Transportasi', 'Tagihan & Utilitas', 'Kesehatan', 'Hiburan', 'Lain-lain').
11. 'confidence': Estimasi kepercayaan pembacaan struk dari 0.0 sampai 1.0.

PENTING:
- Kembalikan HANYA format JSON valid tanpa tag markdown, tanpa backtick, dan tanpa komentar teks apa pun di luar JSON.
Contoh format output persis:
{
  \"merchant\": \"Indomaret Diponegoro\",
  \"date\": \"2026-09-23\",
  \"currency\": \"IDR\",
  \"scan_type\": \"{$defaultScanType}\",
  \"items\": [
    {
      \"name\": \"Ultra Milk Full Cream 250ml\",
      \"qty\": 2,
      \"price\": 7500,
      \"total\": 15000,
      \"category\": \"Makanan & Minuman\"
    }
  ],
  \"subtotal\": 15000,
  \"tax\": 0,
  \"discount\": 0,
  \"total\": 15000,
  \"suggested_category\": \"Makanan & Minuman\",
  \"confidence\": 0.95
}";
    }

    /**
     * Clean, parse, and validate JSON from AI response.
     */
    private function parseAndValidateJson(string $raw, int $imageCount = 1): ?array
    {
        // Strip <think>...</think> tags if reasoning model is used
        $clean = preg_replace('/<think>.*?<\/think>/s', '', $raw);

        // Strip markdown ```json ... ``` codeblocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $clean, $matches)) {
            $clean = $matches[1];
        }

        $clean = trim($clean);

        // Find JSON object boundaries
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $clean = substr($clean, $start, $end - $start + 1);
        }

        $data = json_decode($clean, true);
        if (!is_array($data)) {
            Log::warning('AiReceiptService json_decode failed on raw output', ['raw' => substr($raw, 0, 500)]);
            return null;
        }

        // Sanitize numbers
        $total    = (float) ($data['total'] ?? 0);
        $subtotal = (float) ($data['subtotal'] ?? $total);
        $tax      = (float) ($data['tax'] ?? 0);
        $discount = (float) ($data['discount'] ?? 0);

        // If total is 0 but subtotal is present, fallback
        if ($total <= 0 && $subtotal > 0) {
            $total = $subtotal + $tax - $discount;
        }

        // Sanitize items
        $items = [];
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (!is_array($item)) continue;
                $name = trim($item['name'] ?? '');
                if (!$name) continue;

                $qty   = (float) ($item['qty'] ?? 1);
                $price = (float) ($item['price'] ?? 0);
                $itemTotal = (float) ($item['total'] ?? ($qty * $price));

                if ($price <= 0 && $qty > 0 && $itemTotal > 0) {
                    $price = round($itemTotal / $qty, 2);
                }

                $items[] = [
                    'name'     => $name,
                    'qty'      => $qty > 0 ? $qty : 1,
                    'price'    => $price,
                    'total'    => $itemTotal > 0 ? $itemTotal : ($qty * $price),
                    'category' => $item['category'] ?? 'Lain-lain',
                ];
            }
        }

        // If total is still 0 and we have items, sum item totals
        if ($total <= 0 && !empty($items)) {
            $itemsSum = array_reduce($items, fn($carry, $it) => $carry + (float) ($it['total'] ?? 0), 0.0);
            if ($itemsSum > 0) {
                $subtotal = $subtotal > 0 ? $subtotal : $itemsSum;
                $total    = max(0, $subtotal + $tax - $discount);
            }
        }

        // Fallback date
        $date = trim($data['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Carbon::now()->toDateString();
        } else {
            // Auto-correct anomalous years (e.g. 2016 from 2-digit '16' on Indomaret receipts) to current year (2026)
            $parts = explode('-', $date);
            $year  = (int) $parts[0];
            if ($year < 2024 || $year > 2030) {
                $date = Carbon::now()->year . '-' . $parts[1] . '-' . $parts[2];
            }
        }

        $rawScanType = strtolower(trim((string) ($data['scan_type'] ?? '')));
        if ($imageCount <= 1) {
            $scanType = 'single';
        } elseif (in_array($rawScanType, ['long_receipt', 'multi_receipt'], true)) {
            $scanType = $rawScanType;
        } else {
            $scanType = 'long_receipt';
        }

        return [
            'merchant'           => trim($data['merchant'] ?? 'Toko Belanja') ?: 'Toko Belanja',
            'date'               => $date,
            'currency'           => $data['currency'] ?? 'IDR',
            'scan_type'          => $scanType,
            'pages_count'        => $imageCount,
            'items'              => $items,
            'subtotal'           => max(0, $subtotal),
            'tax'                => max(0, $tax),
            'discount'           => max(0, $discount),
            'total'              => max(0, $total),
            'suggested_category' => trim($data['suggested_category'] ?? 'Belanja & Kebutuhan'),
            'confidence'         => (float) ($data['confidence'] ?? 0.9),
        ];
    }

    /**
     * Match suggested category name to user's actual categories in DB.
     */
    private function matchCategory(int $userId, string $suggestedName, string $secondaryContext = ''): array
    {
        $categories = Category::where(function ($query) use ($userId) {
            $query->where('user_id', $userId)->orWhereNull('user_id');
        })
        ->where(function ($q) {
            $q->where('type', 'expense')->orWhereNull('type');
        })
        ->get(['id', 'name']);

        if ($categories->isEmpty()) {
            return ['id' => null, 'name' => $suggestedName ?: 'Pengeluaran'];
        }

        $sugLower = strtolower(trim($suggestedName));
        $secLower = strtolower(trim($secondaryContext));

        // 1. Exact match
        foreach ($categories as $cat) {
            if (strtolower($cat->name) === $sugLower) {
                return ['id' => $cat->id, 'name' => $cat->name];
            }
        }

        // 2. Partial containment match
        foreach ($categories as $cat) {
            $cName = strtolower($cat->name);
            if (str_contains($sugLower, $cName) || str_contains($cName, $sugLower)) {
                return ['id' => $cat->id, 'name' => $cat->name];
            }
        }

        // 3. Match against secondary context (merchant / item name)
        if ($secLower) {
            foreach ($categories as $cat) {
                $cName = strtolower($cat->name);
                if (str_contains($secLower, $cName)) {
                    return ['id' => $cat->id, 'name' => $cat->name];
                }
            }
        }

        // 4. Default to Belanja / Makanan / first category
        $fallback = $categories->first(function ($cat) {
            $name = strtolower($cat->name);
            return str_contains($name, 'belanja') || str_contains($name, 'makan') || str_contains($name, 'kebutuhan');
        }) ?? $categories->first();

        return ['id' => $fallback->id, 'name' => $fallback->name];
    }
}
