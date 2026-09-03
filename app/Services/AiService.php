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
     * Test the user's configured AI connection with a minimal message.
     * Returns array: ['ok' => bool, 'provider' => string, 'model' => string, 'message' => string]
     */
    public function testConnection(User $user): array
    {
        $provider = $this->makeProvider($user);
        if (is_string($provider)) {
            return ['ok' => false, 'message' => $provider];
        }

        $response = $provider->chat([
            ['role' => 'user', 'content' => 'Reply with exactly: OK'],
        ], 0.0);

        $isQuotaError = str_starts_with($response, 'QUOTA_EXCEEDED|');
        $isError      = $isQuotaError
            || str_contains(strtolower($response), 'error')
            || str_contains(strtolower($response), 'tidak valid')
            || str_contains(strtolower($response), 'tidak tersedia');

        if ($isQuotaError) {
            $response = $this->formatQuotaError($response);
        }

        return [
            'ok'       => !$isError,
            'provider' => $provider->getProviderName(),
            'model'    => $provider->getModelName(),
            'message'  => $isError ? $response : 'Koneksi berhasil!',
        ];
    }

    /**
     * Suggest the best category for a transaction based on its description.
     * Works purely with string matching — does NOT require AI.
     */
    public function suggestCategory(array $categories, string $description, string $type = 'expense'): ?array
    {
        if (empty($categories) || empty($description)) {
            return null;
        }

        $categoryList = collect($categories)
            ->filter(fn($c) => ($c['type'] ?? $type) === $type || !isset($c['type']))
            ->map(fn($c) => "ID:{$c['id']} => {$c['name']}")
            ->implode(', ');

        $prompt = "Kamu adalah sistem kategorisasi transaksi keuangan. Dari deskripsi transaksi berikut, pilih ID kategori yang paling sesuai dari daftar yang tersedia. Jawab HANYA dengan ID angka, tidak ada teks lain.\n\nTipe transaksi: {$type}\nDeskripsi: \"{$description}\"\nDaftar kategori (ID => Nama): {$categoryList}\n\nJawab hanya dengan angka ID kategori yang paling sesuai:";

        $messages  = [['role' => 'user', 'content' => $prompt]];
        $provider  = $this->makeProvider(null);

        if (is_string($provider)) {
            // AI not available — simple keyword fallback
            return $this->suggestCategoryFallback($categories, $description, $type);
        }

        $response   = $provider->chat($messages, 0.1);
        $categoryId = (int) trim(preg_replace('/\D/', '', $response));

        if ($categoryId > 0) {
            $match = collect($categories)->firstWhere('id', $categoryId);
            if ($match) {
                return ['id' => $match['id'], 'name' => $match['name']];
            }
        }

        return $this->suggestCategoryFallback($categories, $description, $type);
    }

    /**
     * Recommend monthly budget limits per category based on 3 months of history.
     * Works with or without AI — AI enhances the reason text.
     */
    public function budgetRecommendations(User $user): array
    {
        $threeMonthsAgo = Carbon::now()->subMonths(3)->startOfMonth()->toDateString();
        $today          = Carbon::now()->toDateString();

        $spending = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$threeMonthsAgo, $today])
            ->with('category:id,name')
            ->get()
            ->groupBy('category_id')
            ->map(function ($group) {
                $monthly = $group->sum('amount') / 3;
                return [
                    'category_id'   => $group->first()->category_id,
                    'category_name' => $group->first()->category?->name ?? 'Lain-lain',
                    'avg_monthly'   => round($monthly),
                    'total_3months' => $group->sum('amount'),
                ];
            })
            ->values()
            ->toArray();

        if (empty($spending)) {
            return ['message' => 'Belum cukup data transaksi (minimal 1 bulan) untuk membuat rekomendasi.', 'recommendations' => []];
        }

        // Build deterministic recommendations (always available)
        $recommendations = collect($spending)->map(function ($s) {
            $limit  = round($s['avg_monthly'] * 0.85 / 10000) * 10000; // 85% of avg, rounded to 10k
            $limit  = max($limit, 50000); // Minimum 50k
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

    /**
     * Build and return an AiProvider for the given user (or null for no-user scenario).
     * Returns error string if AI is not configured or disabled.
     */
    private function makeProvider(?User $user): \App\Services\Ai\AiProvider|string
    {
        if (!$user) {
            // Server-side use with no user context (e.g. suggest-category fallback)
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

        $apiKey = $this->keyService->getDecryptedKey($user, $provider);

        if (!$apiKey) {
            try {
                $apiKey = Crypt::decryptString($encKey);
            } catch (\Throwable $e) {
                Log::error('Failed to decrypt AI API key', ['user_id' => $user->id]);
                return 'Gagal mendekripsi API key. Silakan simpan ulang API key di Settings → AI Chatbot.';
            }
        }

        if (empty($model)) {
            $model = AiProviderFactory::defaultModel($provider);
        }

        return AiProviderFactory::make($provider, $apiKey, $model);
    }

    /**
     * Format the QUOTA_EXCEEDED pipe-delimited signal into a user-friendly message.
     */
    public function formatQuotaError(string $raw): string
    {
        // Format: "QUOTA_EXCEEDED|Provider Name|dashboard.url"
        $parts    = explode('|', $raw);
        $prov     = $parts[1] ?? 'Provider';
        $dashboard = $parts[2] ?? 'dashboard provider Anda';

        return "Kuota / saldo API {$prov} Anda habis. Silakan recharge di {$dashboard} atau ganti API key di Settings → AI Chatbot.";
    }

    /**
     * Check if a response string is a quota error signal.
     */
    public function isQuotaError(string $response): bool
    {
        return str_starts_with($response, 'QUOTA_EXCEEDED|');
    }

    private function suggestCategoryFallback(array $categories, string $description, string $type): ?array
    {
        $desc  = strtolower($description);
        $scored = collect($categories)
            ->filter(fn($c) => ($c['type'] ?? $type) === $type)
            ->map(function ($c) use ($desc) {
                $name   = strtolower($c['name']);
                $score  = similar_text($desc, $name);
                // Bonus for keyword match
                if (str_contains($desc, $name) || str_contains($name, $desc)) {
                    $score += 20;
                }
                return array_merge($c, ['_score' => $score]);
            })
            ->sortByDesc('_score')
            ->first();

        return $scored ? ['id' => $scored['id'], 'name' => $scored['name']] : null;
    }

    private function buildUserContext(User $user): array
    {
        // Cache 2 menit — data keuangan user jarang berubah dalam hitungan detik.
        // Di-invalidate otomatis oleh ProcessTransactionSideEffects job saat ada transaksi baru.
        return Cache::remember("ai_context:{$user->id}", 120, fn () => $this->buildUserContextRaw($user));
    }

    private function buildUserContextRaw(User $user): array
    {
        $now        = Carbon::now();
        $startMonth = $now->copy()->startOfMonth()->toDateString();
        $endMonth   = $now->copy()->endOfMonth()->toDateString();
        $startWeek  = $now->copy()->startOfWeek()->toDateString();
        $endWeek    = $now->copy()->endOfWeek()->toDateString();
        $last30     = $now->copy()->subDays(30)->toDateString();

        $totalBalance     = $user->accounts()->sum('balance');
        $incomeThisMonth  = Transaction::where('user_id', $user->id)->where('type', 'income')->whereBetween('transaction_date', [$startMonth, $endMonth])->sum('amount');
        $expenseThisMonth = Transaction::where('user_id', $user->id)->where('type', 'expense')->whereBetween('transaction_date', [$startMonth, $endMonth])->sum('amount');
        $expenseThisWeek  = Transaction::where('user_id', $user->id)->where('type', 'expense')->whereBetween('transaction_date', [$startWeek, $endWeek])->sum('amount');

        $lastWeek        = $now->copy()->subWeek();
        $expenseLastWeek = Transaction::where('user_id', $user->id)->where('type', 'expense')
            ->whereBetween('transaction_date', [
                $lastWeek->copy()->startOfWeek()->toDateString(),
                $lastWeek->copy()->endOfWeek()->toDateString(),
            ])->sum('amount');

        $topCategories = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$last30, $now->toDateString()])
            ->with('category:id,name')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn($r) => ['category' => $r->category?->name ?? 'Lain-lain', 'amount' => (float) $r->total])
            ->toArray();

        $budgets = Budget::where('user_id', $user->id)
            ->where('month', $now->month)
            ->where('year', $now->year)
            ->with('category:id,name')
            ->get()
            ->map(function ($b) use ($user, $now) {
                $spent = Transaction::where('user_id', $user->id)
                    ->where('category_id', $b->category_id)
                    ->where('type', 'expense')
                    ->whereMonth('transaction_date', $now->month)
                    ->whereYear('transaction_date', $now->year)
                    ->sum('amount');
                return [
                    'category' => $b->category?->name,
                    'limit'    => (float) $b->limit_amount,
                    'spent'    => (float) $spent,
                    'percent'  => $b->limit_amount > 0 ? round(($spent / $b->limit_amount) * 100) : 0,
                ];
            })
            ->toArray();

        $goals = $user->goals()
            ->limit(3)
            ->get(['name', 'target_amount', 'current_amount'])
            ->map(fn($g) => [
                'name'    => $g->name,
                'target'  => (float) $g->target_amount,
                'current' => (float) $g->current_amount,
                'percent' => $g->target_amount > 0 ? round(($g->current_amount / $g->target_amount) * 100) : 0,
            ])
            ->toArray();

        return compact('totalBalance', 'incomeThisMonth', 'expenseThisMonth', 'expenseThisWeek', 'expenseLastWeek', 'topCategories', 'budgets', 'goals', 'now');
    }

    private function buildSystemPrompt(array $ctx): string
    {
        $text = $this->contextToText($ctx);
        return "Kamu adalah MoneFin AI — asisten keuangan personal yang cerdas, ramah, dan membantu. Kamu berbicara dalam bahasa yang sama dengan pertanyaan pengguna (Bahasa Indonesia atau Inggris). Kamu memiliki akses ke data keuangan nyata pengguna berikut:\n\n{$text}\n\nPedoman:\n- Berikan analisis dan saran yang jelas, lengkap, spesifik, dan actionable berbasis data nyata di atas\n- Gunakan format yang mudah dibaca dengan bullet points dan langkah-langkah konkret\n- Jangan pernah meminta data finansial tambahan karena seluruh data sudah tersedia di atas\n- Selalu berikan motivasi dan kata-kata positif untuk membantu pengguna mencapai kesehatan finansial";
    }

    private function contextToText(array $ctx): string
    {
        $lines = [];
        $fmt   = fn($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

        $lines[] = "Tanggal sekarang: {$ctx['now']->format('d F Y')}";
        $lines[] = "Total saldo semua akun: " . $fmt($ctx['totalBalance']);
        $lines[] = "Pemasukan bulan ini: " . $fmt($ctx['incomeThisMonth']);
        $lines[] = "Pengeluaran bulan ini: " . $fmt($ctx['expenseThisMonth']);
        $lines[] = "Selisih (tabungan) bulan ini: " . $fmt($ctx['incomeThisMonth'] - $ctx['expenseThisMonth']);
        $lines[] = "Pengeluaran minggu ini: " . $fmt($ctx['expenseThisWeek']);
        $lines[] = "Pengeluaran minggu lalu: " . $fmt($ctx['expenseLastWeek']);

        if (!empty($ctx['topCategories'])) {
            $lines[] = "\nTop 5 kategori pengeluaran (30 hari):";
            foreach ($ctx['topCategories'] as $i => $c) {
                $lines[] = "  " . ($i + 1) . ". {$c['category']}: " . $fmt($c['amount']);
            }
        }

        if (!empty($ctx['budgets'])) {
            $lines[] = "\nBudget bulan ini:";
            foreach ($ctx['budgets'] as $b) {
                $status  = $b['percent'] >= 90 ? 'Hampir habis' : ($b['percent'] >= 75 ? 'Perlu hati-hati' : 'Aman');
                $lines[] = "  - {$b['category']}: {$fmt($b['spent'])} / {$fmt($b['limit'])} ({$b['percent']}%) [{$status}]";
            }
        }

        if (!empty($ctx['goals'])) {
            $lines[] = "\nTarget tabungan (Goals):";
            foreach ($ctx['goals'] as $g) {
                $lines[] = "  - {$g['name']}: {$fmt($g['current'])} / {$fmt($g['target'])} ({$g['percent']}%)";
            }
        }

        return implode("\n", $lines);
    }
}
