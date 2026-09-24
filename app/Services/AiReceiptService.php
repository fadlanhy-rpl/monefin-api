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
     * Scan and parse receipt image using user's BYOK Vision LLM.
     *
     * @param  User   $user
     * @param  string $imageBase64 Raw base64 encoded image (without data: prefix)
     * @param  string $mimeType    e.g. 'image/jpeg', 'image/png', 'image/webp'
     * @return array
     */
    public function scanReceipt(User $user, string $imageBase64, string $mimeType = 'image/jpeg'): array
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

        $userModel = $aiConfig['model'] ?? '';
        $baseUrl   = $aiConfig['base_url'] ?? null;

        $prompt = $this->buildReceiptPrompt();

        try {
            $rawJson = $this->callVisionProvider($provider, $apiKey, $userModel, $baseUrl, $prompt, $imageBase64, $mimeType);
        } catch (\Throwable $e) {
            Log::error('AiReceiptService callVisionProvider error', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
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

        $parsed = $this->parseAndValidateJson($rawJson);

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
     * Dispatch multimodal request to the appropriate vision provider.
     */
    private function callVisionProvider(
        string $provider,
        string $apiKey,
        string $model,
        ?string $baseUrl,
        string $prompt,
        string $imageBase64,
        string $mimeType
    ): string {
        $verifySSL = (bool) config('services.ai.verify_ssl', false);

        // 1. Google Gemini Native API — try v1beta/v1 with auto-upgrade to active 3.x models
        if ($provider === 'gemini') {
            $geminiModel = !empty($model) ? $model : 'gemini-3.6-flash';
            // Automatically upgrade deprecated models (gemini-2.0-flash, gemini-2.5-flash, etc.)
            if (in_array($geminiModel, ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro']) || !str_contains($geminiModel, 'gemini')) {
                $geminiModel = 'gemini-3.6-flash';
            }

            $nativePayload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data'      => $imageBase64,
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature'      => 0.1,
                    'responseMimeType' => 'application/json',
                ],
            ];

            // Candidate models: requested model first, then fallback to gemini-3.6-flash and gemini-flash-latest
            // Candidate models: requested model first, then fallback to gemini-flash-latest, 3.6-flash, and 3.5-flash
            $candidateModels = array_unique([$geminiModel, 'gemini-flash-latest', 'gemini-3.6-flash', 'gemini-3.5-flash']);
            $apiVersions     = ['v1beta', 'v1'];
            $nativeRes       = null;

            foreach ($candidateModels as $currentModel) {
                foreach ($apiVersions as $apiVersion) {
                    $endpoint = "https://generativelanguage.googleapis.com/{$apiVersion}/models/{$currentModel}:generateContent?key={$apiKey}";
                    try {
                        $attempt = Http::timeout(45)
                            ->withOptions(['verify' => $verifySSL])
                            ->post($endpoint, $nativePayload);
                    } catch (\Throwable $e) {
                        Log::warning("Gemini {$apiVersion} request to {$currentModel} failed: " . $e->getMessage());
                        continue;
                    }

                    if ($attempt->successful()) {
                        $nativeRes = $attempt;
                        break 2;
                    }

                    // If 503 (high demand), 404 (model not found), or other error, try next candidate model
                    Log::warning("Gemini {$apiVersion} model {$currentModel} returned {$attempt->status()}, trying fallback model...", [
                        'status' => $attempt->status(),
                        'body'   => substr($attempt->body(), 0, 200),
                    ]);
                    $nativeRes = $attempt;
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
            $claudeModel = !empty($model) ? $model : 'claude-3-5-sonnet-20241022';
            $endpoint    = 'https://api.anthropic.com/v1/messages';

            $payload = [
                'model'      => $claudeModel,
                'max_tokens' => 4096,
                'temperature' => 0.1,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type'   => 'image',
                                'source' => [
                                    'type'       => 'base64',
                                    'media_type' => $mimeType,
                                    'data'       => $imageBase64,
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => $prompt,
                            ],
                        ],
                    ],
                ],
            ];

            $res = Http::timeout(60)
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
        if ($provider === 'openai') {
            $visionModel = in_array($model, ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo']) ? $model : 'gpt-4o-mini';
            $baseEndpoint = 'https://api.openai.com/v1';
        } elseif ($provider === 'groq') {
            $visionModel = 'llama-3.2-11b-vision-preview';
            $baseEndpoint = 'https://api.groq.com/openai/v1';
        } elseif ($provider === 'gemini') {
            $visionModel = !empty($model) ? $model : 'gemini-3.6-flash';
            if (in_array($visionModel, ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro']) || !str_contains($visionModel, 'gemini')) {
                $visionModel = 'gemini-3.6-flash';
            }
            $baseEndpoint = 'https://generativelanguage.googleapis.com/v1beta/openai';
        } else {
            $baseEndpoint = rtrim($baseUrl ?: 'https://api.openai.com/v1', '/');
            if (str_ends_with($baseEndpoint, '/chat/completions')) {
                $baseEndpoint = substr($baseEndpoint, 0, -strlen('/chat/completions'));
            }
        }

        $payload = [
            'model'       => $visionModel,
            'temperature' => 0.1,
            'max_tokens'  => 4096,
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type'      => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$imageBase64}",
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $postVision = function (bool $verify) use ($baseEndpoint, $apiKey, $payload) {
            return Http::timeout(55)  // 55s: toleransi model gratis OpenRouter yang lambat
                ->withOptions(['verify' => $verify])
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => config('app.url', 'https://monefin.web.id'),
                    'X-Title'       => 'MoneFin',
                ])
                ->post("{$baseEndpoint}/chat/completions", $payload);
        };

        try {
            $res = $postVision($verifySSL);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'cURL error 28') || str_contains($e->getMessage(), 'timed out')) {
                throw new \RuntimeException(
                    "Model AI '{$visionModel}' tidak merespons (timeout 55 detik). " .
                    "Antrean server OpenRouter sedang penuh atau model tidak aktif. " .
                    "Rekomendasi: Gunakan Google Gemini (gratis, paling cepat & stabil) dengan mengganti Provider ke 'Google Gemini' " .
                    "di Pengaturan → AI Chatbot. Atau coba model OpenRouter lain: google/gemini-2.0-flash-exp:free."
                );
            }

            if (str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'SSL certificate')) {
                try {
                    $res = $postVision(false);
                } catch (\Throwable $retryEx) {
                    if (str_contains($retryEx->getMessage(), 'cURL error 28') || str_contains($retryEx->getMessage(), 'timed out')) {
                        throw new \RuntimeException(
                            "Model AI '{$visionModel}' tidak merespons (timeout 55 detik). " .
                            "Antrean server OpenRouter sedang penuh. Ganti ke Google Gemini di Pengaturan → AI Chatbot."
                        );
                    }
                    throw $retryEx;
                }
            } else {
                throw $e;
            }
        }

        // Automatic retry once on HTTP 429 (Transient rate limit / burst limit backoff)
        if ($res->status() === 429) {
            Log::info('AiReceiptService rate-limited (429), retrying after 2s backoff...', [
                'provider' => $provider,
                'model'    => $visionModel,
            ]);
            sleep(2);
            try {
                $res = $postVision(false);
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

        return $res->json()['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Build strict, comprehensive system prompt for Indonesian receipt extraction.
     */
    private function buildReceiptPrompt(): string
    {
        $today = Carbon::now()->toDateString();

        return "Kamu adalah sistem Optical Document Extraction khusus struk belanja untuk aplikasi keuangan MoneFin (Indonesia).
Tugasmu: Ekstrak seluruh informasi finansial dari foto struk belanja berikut dengan presisi tinggi.

ATURAN EKSTRAKSI:
1. 'merchant': Nama toko/merchant/restoran (contoh: 'Indomaret', 'Alfamart', 'Superindo', 'Kopi Kenangan', 'SPBU Pertamina', 'McDonalds'). Jika tidak ada, tulis 'Toko Belanja'.
2. 'date': Tanggal transaksi dalam format 'YYYY-MM-DD'. Tahun saat ini adalah 2026 (hari ini: '{$today}'). Jika tanggal di struk menampilkan 2 digit tahun seperti 'DD/MM/YY' atau 'DD-MM-YY' (misal: '14/09/16' atau '14/09/26'), pastikan tahun yang dihasilkan adalah 2026 ('2026-09-14'), BUKAN 2016. Jika tanggal tidak terbaca, gunakan tanggal hari ini: '{$today}'.
3. 'currency': Mata uang (umumnya 'IDR').
4. 'items': Daftar item barang/menu yang dibeli. Setiap item memiliki:
   - 'name': Nama barang (singkatan struk mohon dirapikan bila mudah dikenali, misal 'ULTRA TPK 250ML' -> 'Ultra Milk 250ml').
   - 'qty': Jumlah kuantitas (angka integer/float, default 1).
   - 'price': Harga satuan dalam angka tanpa titik/koma (misal 15000).
   - 'total': Total harga item tersebut (qty * price).
   - 'category': Kategori perkiraan untuk item tersebut (contoh: 'Makanan & Minuman', 'Belanja & Kebutuhan', 'Kesehatan', 'Transportasi', 'Tagihan & Utilitas', 'Hiburan', 'Lain-lain').
5. 'subtotal': Total belanja sebelum pajak dan diskon.
6. 'tax': Nilai PPN/pajak jika ada (angka murni, default 0).
7. 'discount': Nilai diskon/potongan harga jika ada (angka murni positif, default 0).
8. 'total': TOTAL AKHIR yang benar-benar dibayarkan oleh pelanggan (setelah pajak dan diskon).
9. 'suggested_category': Kategori utama keseluruhan transaksi (pilih yang paling cocok: 'Makanan & Minuman', 'Belanja & Kebutuhan', 'Transportasi', 'Tagihan & Utilitas', 'Kesehatan', 'Hiburan', 'Lain-lain').
10. 'confidence': Estimasi kepercayaan pembacaan struk dari 0.0 sampai 1.0.

PENTING:
- Kembalikan HANYA format JSON valid tanpa tag markdown, tanpa backtick, dan tanpa komentar teks apa pun di luar JSON.
Contoh format output persis:
{
  \"merchant\": \"Indomaret Diponegoro\",
  \"date\": \"2026-09-23\",
  \"currency\": \"IDR\",
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
    private function parseAndValidateJson(string $raw): ?array
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

        return [
            'merchant'           => trim($data['merchant'] ?? 'Toko Belanja') ?: 'Toko Belanja',
            'date'               => $date,
            'currency'           => $data['currency'] ?? 'IDR',
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
