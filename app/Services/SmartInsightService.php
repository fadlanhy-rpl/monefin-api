<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Dual-mode Smart Insight Service with full localization ('en' and 'id').
 * - AI enabled  → call user's configured AI provider for contextual insight
 * - AI disabled → return deterministic rule-based insight
 *
 * Supported pages: dashboard | categories | budgets | accounts | goals
 */
class SmartInsightService
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * Invalidate all cached smart insights for a user
     */
    public static function invalidateUserCache(User $user): void
    {
        foreach (['dashboard', 'categories', 'budgets', 'accounts', 'goals'] as $p) {
            Cache::forget("smart_insight:{$user->id}:{$p}:id");
            Cache::forget("smart_insight:{$user->id}:{$p}:en");
        }
    }

    public function getInsight(User $user, string $page, string $lang = 'id'): array
    {
        $langNormalized = str_starts_with(strtolower($lang), 'en') ? 'en' : 'id';
        $cacheKey = "smart_insight:{$user->id}:{$page}:{$langNormalized}";

        return Cache::remember($cacheKey, 1800, function () use ($user, $page, $langNormalized) {
            $prefs     = $user->preferences ?? [];
            $aiEnabled = $prefs['ai_enabled'] ?? false;

            $context = $this->buildPageContext($user, $page, $langNormalized);

            if ($aiEnabled) {
                $result = $this->getAiInsight($user, $page, $context, $prefs, $langNormalized);
                if ($result !== null) {
                    return $result;
                }
                // AI failed — fall through to deterministic
            }

            return $this->getDeterministicInsight($page, $context, $prefs, $langNormalized);
        });
    }

    // ─── AI Mode ─────────────────────────────────────────────────────────────

    private function getAiInsight(User $user, string $page, array $ctx, array $prefs, string $lang): ?array
    {
        $aiConfig = $prefs['ai_config'] ?? [];
        $provider = $aiConfig['provider'] ?? '';
        $model    = $aiConfig['model']    ?? '';

        $contextText = $this->contextToText($ctx, $page);
        $pageLabel   = $this->pageLabel($page, $lang);

        $prompt = $lang === 'en'
            ? "You are MoneFin AI financial advisor. Provide 1 specific, actionable, and personal financial insight for the '{$pageLabel}' page based on the following user data:\n\n{$contextText}\n\nRespond ONLY in JSON format:\n{\"title\": \"Short catchy title\", \"body\": \"1-2 actionable and personal sentences\", \"action_label\": \"Action button label\", \"action_url\": \"/target-path\", \"type\": \"expense|budget|goal|saving|alert\"}"
            : "Kamu adalah MoneFin AI financial advisor. Berikan 1 insight finansial yang spesifik, actionable, dan personal untuk halaman '{$pageLabel}' berdasarkan data berikut:\n\n{$contextText}\n\nBerikan respons dalam format JSON (HANYA JSON):\n{\"title\": \"Judul singkat\", \"body\": \"Penjelasan 1-2 kalimat yang actionable dan personal\", \"action_label\": \"Label tombol aksi\", \"action_url\": \"/halaman-tujuan\", \"type\": \"expense|budget|goal|saving|alert\"}";

        try {
            $raw = $this->ai->chat($user, $prompt, []);

            if ($this->ai->isQuotaError($raw)) {
                return null; // Fall back to deterministic
            }

            $clean  = trim(preg_replace('/```(?:json)?|```/', '', $raw));
            $parsed = json_decode($clean, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($parsed['title'], $parsed['body'])) {
                $providerLabel = $this->providerLabel($provider, $model);
                return array_merge($parsed, [
                    'source'       => 'ai',
                    'source_label' => "Powered by {$providerLabel}",
                ]);
            }
        } catch (\Throwable $e) {
            // AI error — fall through
        }

        return null;
    }

    // ─── Deterministic Mode ───────────────────────────────────────────────────

    private function getDeterministicInsight(string $page, array $ctx, array $prefs, string $lang): array
    {
        $insight = match ($page) {
            'categories' => $this->insightCategories($ctx, $lang),
            'budgets'    => $this->insightBudgets($ctx, $lang),
            'accounts'   => $this->insightAccounts($ctx, $lang),
            'goals'      => $this->insightGoals($ctx, $lang),
            default      => $this->insightDashboard($ctx, $lang),
        };

        $aiEnabled = $prefs['ai_enabled'] ?? false;

        if ($aiEnabled) {
            $insight['source_label'] = $lang === 'en' ? 'MoneFin Engine (AI unavailable)' : 'MoneFin Engine (AI tidak tersedia)';
        } else {
            $insight['source_label'] = 'MoneFin Engine';
        }

        $insight['source'] = 'engine';
        return $insight;
    }

    private function insightDashboard(array $ctx, string $lang): array
    {
        $expenseWeek   = $ctx['expense_week'] ?? 0;
        $expenseLastWk = $ctx['expense_last_week'] ?? 0;
        $income        = $ctx['income_month'] ?? 0;
        $expense       = $ctx['expense_month'] ?? 0;

        $incomeFmt      = $this->formatRupiah($income);
        $expenseFmt     = $this->formatRupiah($expense);
        $expenseWeekFmt = $this->formatRupiah($expenseWeek);

        if ($expense > $income && $income > 0) {
            return [
                'title'        => $lang === 'en' ? 'Watch Out for Overspending' : 'Waspadai Pengeluaran Berlebih',
                'body'         => $lang === 'en'
                    ? "This month's expenses ({$expenseFmt}) exceed income ({$incomeFmt}). Review non-essential expenses to improve cashflow."
                    : "Pengeluaran bulan ini ({$expenseFmt}) melebihi pemasukan ({$incomeFmt}). Evaluasi pengeluaran non-esensial untuk memperbaiki arus kas.",
                'action_label' => $lang === 'en' ? 'View Transactions' : 'Lihat Transaksi',
                'action_url'   => '/transactions',
                'type'         => 'alert',
            ];
        }

        if ($expenseLastWk > 0 && $expenseWeek < $expenseLastWk * 0.85) {
            $saved    = $expenseLastWk - $expenseWeek;
            $savedFmt = $this->formatRupiah($saved);
            return [
                'title'        => $lang === 'en' ? 'Weekly Spending Decreased' : 'Pengeluaran Minggu Ini Menurun',
                'body'         => $lang === 'en'
                    ? "Great job! This week's spending of {$expenseWeekFmt} is {$savedFmt} lower than last week. Keep it up!"
                    : "Bagus! Pengeluaran minggu ini {$expenseWeekFmt} — {$savedFmt} lebih hemat dari minggu lalu. Pertahankan!",
                'action_label' => $lang === 'en' ? 'View Reports' : 'Lihat Laporan',
                'action_url'   => '/reports',
                'type'         => 'saving',
            ];
        }

        if ($expenseLastWk > 0 && $expenseWeek > $expenseLastWk * 1.25) {
            $increase    = $expenseWeek - $expenseLastWk;
            $increaseFmt = $this->formatRupiah($increase);
            return [
                'title'        => $lang === 'en' ? 'Weekly Spending Increased' : 'Pengeluaran Minggu Ini Meningkat',
                'body'         => $lang === 'en'
                    ? "Spending this week is {$expenseWeekFmt}, up {$increaseFmt} from last week. Check spiking categories."
                    : "Pengeluaran minggu ini {$expenseWeekFmt}, naik {$increaseFmt} dari minggu lalu. Cek kategori yang melonjak.",
                'action_label' => $lang === 'en' ? 'View Transactions' : 'Lihat Transaksi',
                'action_url'   => '/transactions',
                'type'         => 'alert',
            ];
        }

        $savings    = $income - $expense;
        $savingsFmt = $this->formatRupiah(max(0, $savings));
        return [
            'title'        => $lang === 'en' ? 'Cash Flow Summary' : 'Ringkasan Arus Kas',
            'body'         => $income > 0
                ? ($lang === 'en'
                    ? "Income {$incomeFmt}, expenses {$expenseFmt}. You saved {$savingsFmt} this month."
                    : "Pemasukan {$incomeFmt}, pengeluaran {$expenseFmt}. Anda berhasil menyisihkan {$savingsFmt} bulan ini.")
                : ($lang === 'en'
                    ? "Start recording income and expenses to get an accurate cash flow summary."
                    : "Mulai catat pemasukan dan pengeluaran untuk mendapatkan ringkasan arus kas yang akurat."),
            'action_label' => $lang === 'en' ? 'View Reports' : 'Lihat Laporan',
            'action_url'   => '/reports',
            'type'         => 'expense',
        ];
    }

    private function insightCategories(array $ctx, string $lang): array
    {
        $budgets     = $ctx['budgets'] ?? [];
        $topCategory = $ctx['top_category'] ?? null;

        $critical = array_filter($budgets, fn($b) => $b['percent'] >= 85);
        if (!empty($critical)) {
            $worst = array_reduce($critical, fn($c, $b) => (!$c || $b['percent'] > $c['percent']) ? $b : $c, null);
            if ($worst) {
                $categoryName = $worst['category'];
                $percentValue = $worst['percent'];
                $spentFmt     = $this->formatRupiah($worst['spent']);
                $limitFmt     = $this->formatRupiah($worst['limit']);
                return [
                    'title'        => $lang === 'en' ? "Budget '{$categoryName}' Almost Exhausted" : "Anggaran '{$categoryName}' Hampir Habis",
                    'body'         => $lang === 'en'
                        ? "Category '{$categoryName}' has reached {$percentValue}% of its limit ({$spentFmt} / {$limitFmt}). Consider cutting back."
                        : "Kategori '{$categoryName}' sudah mencapai {$percentValue}% dari batas anggaran ({$spentFmt} / {$limitFmt}). Pertimbangkan mengurangi pengeluaran.",
                    'action_label' => $lang === 'en' ? 'Manage Budget' : 'Atur Budget',
                    'action_url'   => '/budgets',
                    'type'         => 'alert',
                ];
            }
        }

        if ($topCategory) {
            $topName = $topCategory['name'];
            $pct     = $topCategory['percent'];
            if ($pct <= 0) {
                return [
                    'title'        => $lang === 'en' ? 'Budget Realization is Well Controlled' : 'Realisasi Anggaran Sangat Baik',
                    'body'         => $lang === 'en'
                        ? "Expense budget is well controlled. Highest spending category is \"{$topName}\" at {$pct}%. Keep it up!"
                        : "Realisasi anggaran pengeluaran terkendali dengan baik. Penggunaan tertinggi ada pada kategori \"{$topName}\" sebesar {$pct}%. Pertahankan!",
                    'action_label' => $lang === 'en' ? 'View Reports' : 'Lihat Laporan',
                    'action_url'   => '/reports',
                    'type'         => 'saving',
                ];
            }
            return [
                'title'        => $lang === 'en' ? 'Weekly Spending Analysis' : 'Analisis Pengeluaran Minggu Ini',
                'body'         => $lang === 'en'
                    ? "Spending is well managed. Highest utilization is in \"{$topName}\" at {$pct}% of budget limit."
                    : "Pengeluaran terkendali dengan baik. Penggunaan tertinggi pada kategori \"{$topName}\" sebesar {$pct}% dari anggaran.",
                'action_label' => $lang === 'en' ? 'View Reports' : 'Lihat Laporan',
                'action_url'   => '/reports',
                'type'         => 'expense',
            ];
        }

        return [
            'title'        => $lang === 'en' ? 'Start Setting Category Budgets' : 'Mulai Buat Anggaran Kategori',
            'body'         => $lang === 'en'
                ? "Set budgets per category to manage your spending with greater control and precision."
                : "Buat anggaran per kategori untuk mengontrol pengeluaran Anda secara lebih efektif dan terukur.",
            'action_label' => $lang === 'en' ? 'Create Budget' : 'Buat Budget',
            'action_url'   => '/budgets',
            'type'         => 'budget',
        ];
    }

    private function insightBudgets(array $ctx, string $lang): array
    {
        $totalBudget = $ctx['total_budget'] ?? 0;
        $totalSpent  = $ctx['total_spent']  ?? 0;
        $remaining   = $totalBudget - $totalSpent;
        $overallPct  = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100) : 0;

        if ($totalBudget <= 0) {
            return [
                'title'        => $lang === 'en' ? 'Set Monthly Budgets' : 'Buat Anggaran Bulanan',
                'body'         => $lang === 'en'
                    ? "No active budget this month. Start by setting category limits for better financial control."
                    : "Belum ada anggaran aktif bulan ini. Mulai dengan menetapkan batas pengeluaran per kategori untuk kontrol keuangan yang lebih baik.",
                'action_label' => $lang === 'en' ? 'Create Budget' : 'Buat Budget',
                'action_url'   => '/budgets',
                'type'         => 'budget',
            ];
        }

        $potentialQuarterly    = $remaining * 3;
        $remainingFmt          = $this->formatRupiah($remaining);
        $potentialQuarterlyFmt = $this->formatRupiah($potentialQuarterly);

        if ($overallPct <= 50) {
            return [
                'title'        => $lang === 'en' ? 'Budget Very Well Controlled' : 'Anggaran Sangat Terkontrol',
                'body'         => $lang === 'en'
                    ? "Awesome! Only {$overallPct}% of your budget is spent. The remaining {$remainingFmt} can be routed to savings or investments."
                    : "Luar biasa! Baru {$overallPct}% anggaran terpakai. Sisa {$remainingFmt} bisa dialokasikan ke tabungan atau investasi.",
                'action_label' => $lang === 'en' ? 'Enable Auto-Savings' : 'Aktifkan Tabungan Otomatis',
                'action_url'   => '/goals',
                'type'         => 'saving',
            ];
        }

        return [
            'title'        => $lang === 'en' ? 'Smart Savings Based on Your Trends' : 'Tips Hemat Berdasarkan Tren Anda',
            'body'         => $lang === 'en'
                ? "You have used {$overallPct}% of your monthly budget. Remaining {$remainingFmt}. If consistent, potential quarterly savings is {$potentialQuarterlyFmt}."
                : "Anda sudah memakai {$overallPct}% anggaran bulan ini. Sisa {$remainingFmt}. Jika konsisten, potensi tabungan {$potentialQuarterlyFmt} per kuartal.",
            'action_label' => $lang === 'en' ? 'Enable Auto-Savings' : 'Aktifkan Tabungan Otomatis',
            'action_url'   => '/goals',
            'type'         => 'saving',
        ];
    }

    private function insightAccounts(array $ctx, string $lang): array
    {
        $totalBalance = $ctx['total_balance'] ?? 0;
        $accounts     = $ctx['accounts']      ?? [];

        if ($totalBalance <= 0 || empty($accounts)) {
            return [
                'title'        => $lang === 'en' ? 'Connect Financial Accounts' : 'Hubungkan Akun Keuangan',
                'body'         => $lang === 'en'
                    ? "Add bank, e-wallet, or cash accounts to get an accurate total balance overview."
                    : "Tambahkan akun bank, e-wallet, atau kas untuk mendapatkan gambaran saldo total yang lengkap dan akurat.",
                'action_label' => $lang === 'en' ? 'Add Account' : 'Tambah Akun',
                'action_url'   => '/accounts',
                'type'         => 'expense',
            ];
        }

        $totalBalanceFmt = $this->formatRupiah($totalBalance);
        $accountsCount   = count($accounts);

        $dominantAccount = array_reduce($accounts, fn($c, $a) => (!$c || $a['balance'] > $c['balance']) ? $a : $c, null);
        $dominantPct     = $totalBalance > 0 && $dominantAccount ? round(($dominantAccount['balance'] / $totalBalance) * 100) : 0;
        $dominantName    = $dominantAccount ? $dominantAccount['name'] : ($lang === 'en' ? 'Main Account' : 'Akun Utama');

        if ($dominantPct >= 95 && $accountsCount === 1) {
            return [
                'title'        => $lang === 'en' ? 'Smart Tip of the Week' : 'Tips Hemat Pekan Ini',
                'body'         => $lang === 'en'
                    ? "Your entire balance ({$totalBalanceFmt}) is in 1 account. Consider diversifying into investment instruments for optimal returns."
                    : "Seluruh saldo Anda ({$totalBalanceFmt}) ada di 1 akun. Pertimbangkan diversifikasi ke instrumen investasi untuk imbal hasil yang lebih optimal.",
                'action_label' => $lang === 'en' ? 'Explore Investments' : 'Pelajari Investasi',
                'action_url'   => '/reports',
                'type'         => 'saving',
            ];
        }

        if ($dominantPct >= 90) {
            return [
                'title'        => $lang === 'en' ? 'Diversify Your Balance' : 'Diversifikasi Saldo Anda',
                'body'         => $lang === 'en'
                    ? "{$dominantPct}% of your funds are concentrated in '{$dominantName}'. Moving a portion to investments can boost returns."
                    : "{$dominantPct}% saldo Anda terkonsentrasi di '{$dominantName}'. Memindahkan sebagian ke instrumen investasi bisa meningkatkan imbal hasil.",
                'action_label' => $lang === 'en' ? 'Explore Investments' : 'Pelajari Investasi',
                'action_url'   => '/reports',
                'type'         => 'saving',
            ];
        }

        return [
            'title'        => $lang === 'en' ? 'Well Distributed Balance' : 'Saldo Terdistribusi dengan Baik',
            'body'         => $lang === 'en'
                ? "Your total balance of {$totalBalanceFmt} is spread across {$accountsCount} accounts. Good diversification minimizes financial risk."
                : "Total saldo Anda {$totalBalanceFmt} tersebar di {$accountsCount} akun. Diversifikasi yang baik mengurangi risiko finansial.",
            'action_label' => $lang === 'en' ? 'View Financial Reports' : 'Lihat Laporan Keuangan',
            'action_url'   => '/reports',
            'type'         => 'saving',
        ];
    }

    private function insightGoals(array $ctx, string $lang): array
    {
        $goals      = $ctx['goals']       ?? [];
        $savingRate = $ctx['saving_rate'] ?? 0;

        if (empty($goals)) {
            return [
                'title'        => $lang === 'en' ? 'MoneFin Smart Tips' : 'Tips Cerdas MoneFin',
                'body'         => $lang === 'en'
                    ? "Create your first financial goal! Users with active goals save 3× more consistently than those without."
                    : "Buat target keuangan pertama Anda! Pengguna dengan goals aktif cenderung menabung 3× lebih konsisten dibanding yang tidak memiliki target.",
                'action_label' => $lang === 'en' ? 'Create First Goal' : 'Buat Goal Pertama',
                'action_url'   => '/goals',
                'type'         => 'goal',
            ];
        }

        $closest = null;
        foreach ($goals as $g) {
            if ($g['percent'] < 100 && (!$closest || $g['percent'] > $closest['percent'])) {
                $closest = $g;
            }
        }

        if ($closest && $savingRate > 0) {
            $remaining    = $closest['target'] - $closest['current'];
            $monthsNeeded = ceil($remaining / $savingRate);
            $monthLabel   = $lang === 'en'
                ? ($monthsNeeded <= 1 ? 'this month' : "~{$monthsNeeded} months away")
                : ($monthsNeeded <= 1 ? 'bulan ini' : "~{$monthsNeeded} bulan lagi");
            $goalName     = $closest['name'];

            return [
                'title'        => $lang === 'en' ? 'MoneFin Smart Tips' : 'Tips Cerdas MoneFin',
                'body'         => $lang === 'en'
                    ? "At your current savings pace, goal '{$goalName}' can be achieved {$monthLabel}. Set up auto-debit on payday to reach it faster!"
                    : "Dengan laju tabungan saat ini, goal '{$goalName}' bisa tercapai dalam {$monthLabel}. Aktifkan Auto-Debet setiap gajian untuk mempercepat!",
                'action_label' => $lang === 'en' ? 'Activate Now' : 'Aktifkan Sekarang',
                'action_url'   => '/goals',
                'type'         => 'goal',
            ];
        }

        return [
            'title'        => $lang === 'en' ? 'Accelerate Your Goals' : 'Percepat Target Anda',
            'body'         => $lang === 'en'
                ? "Enable auto-debit to your goals every payday to accelerate your target completion up to 3 months earlier."
                : "Aktifkan fitur Auto-Debet ke goals Anda setiap tanggal gajian untuk mempercepat pencapaian target hingga 3 bulan lebih awal.",
                'action_label' => $lang === 'en' ? 'Activate Now' : 'Aktifkan Sekarang',
                'action_url'   => '/goals',
                'type'         => 'goal',
        ];
    }

    // ─── Context Builders ─────────────────────────────────────────────────────

    private function buildPageContext(User $user, string $page, string $lang): array
    {
        $now        = Carbon::now();
        $startMonth = $now->copy()->startOfMonth()->toDateString();
        $endMonth   = $now->copy()->endOfMonth()->toDateString();
        $startWeek  = $now->copy()->startOfWeek()->toDateString();
        $endWeek    = $now->copy()->endOfWeek()->toDateString();
        $lastWeek   = $now->copy()->subWeek();
        $last30     = $now->copy()->subDays(30)->toDateString();

        $incomeMonth  = (float) Transaction::where('user_id', $user->id)->where('type', 'income')->whereBetween('transaction_date', [$startMonth, $endMonth])->sum('amount');
        $expenseMonth = (float) Transaction::where('user_id', $user->id)->where('type', 'expense')->whereBetween('transaction_date', [$startMonth, $endMonth])->sum('amount');

        $ctx = [
            'income_month'      => $incomeMonth,
            'expense_month'     => $expenseMonth,
            'savings_month'     => $incomeMonth - $expenseMonth,
            'expense_week'      => (float) Transaction::where('user_id', $user->id)->where('type', 'expense')->whereBetween('transaction_date', [$startWeek, $endWeek])->sum('amount'),
            'expense_last_week' => (float) Transaction::where('user_id', $user->id)->where('type', 'expense')
                ->whereBetween('transaction_date', [
                    $lastWeek->copy()->startOfWeek()->toDateString(),
                    $lastWeek->copy()->endOfWeek()->toDateString(),
                ])->sum('amount'),
            'total_balance'     => (float) $user->accounts()->sum('balance'),
        ];

        $defaultCat = $lang === 'en' ? 'Others' : 'Lain-lain';

        if (in_array($page, ['categories', 'budgets', 'dashboard'])) {
            $budgets = Budget::where('user_id', $user->id)
                ->where('month', $now->month)
                ->where('year', $now->year)
                ->with('category:id,name')
                ->get()
                ->map(function ($b) use ($user, $now, $defaultCat) {
                    $spent = (float) Transaction::where('user_id', $user->id)
                        ->where('category_id', $b->category_id)
                        ->where('type', 'expense')
                        ->whereMonth('transaction_date', $now->month)
                        ->whereYear('transaction_date', $now->year)
                        ->sum('amount');
                    return [
                        'category' => $b->category?->name ?? $defaultCat,
                        'limit'    => (float) $b->limit_amount,
                        'spent'    => $spent,
                        'percent'  => $b->limit_amount > 0 ? round(($spent / $b->limit_amount) * 100) : 0,
                    ];
                })->toArray();

            $ctx['budgets']      = $budgets;
            $ctx['total_budget'] = array_sum(array_column($budgets, 'limit'));
            $ctx['total_spent']  = array_sum(array_column($budgets, 'spent'));

            $topCategoryByUtil = collect($budgets)->sortByDesc('percent')->first();
            $ctx['top_category'] = $topCategoryByUtil ? [
                'name'    => $topCategoryByUtil['category'],
                'percent' => $topCategoryByUtil['percent'],
            ] : null;
        }

        if (in_array($page, ['accounts'])) {
            $ctx['accounts'] = $user->accounts()->get(['id', 'name', 'balance', 'type'])
                ->map(fn($a) => ['name' => $a->name, 'balance' => (float) $a->balance, 'type' => $a->type])
                ->toArray();
        }

        if (in_array($page, ['goals'])) {
            $ctx['goals'] = $user->goals()
                ->whereColumn('current_amount', '<', 'target_amount')
                ->get(['name', 'target_amount', 'current_amount'])
                ->map(fn($g) => [
                    'name'    => $g->name,
                    'target'  => (float) $g->target_amount,
                    'current' => (float) $g->current_amount,
                    'percent' => $g->target_amount > 0 ? min(100, round(($g->current_amount / $g->target_amount) * 100)) : 0,
                ])->toArray();

            $threeMonthsAgo = $now->copy()->subMonths(3)->startOfMonth()->toDateString();
            $totalSaved = (float) Transaction::where('user_id', $user->id)
                ->where('type', 'income')
                ->whereBetween('transaction_date', [$threeMonthsAgo, $now->toDateString()])
                ->sum('amount')
                - (float) Transaction::where('user_id', $user->id)
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$threeMonthsAgo, $now->toDateString()])
                ->sum('amount');
            $ctx['saving_rate'] = max(0, $totalSaved / 3);
        }

        return $ctx;
    }

    private function contextToText(array $ctx, string $page): string
    {
        $lines = [
            "Halaman: {$this->pageLabel($page, 'id')}",
            "Pemasukan bulan ini: " . $this->formatRupiah($ctx['income_month']),
            "Pengeluaran bulan ini: " . $this->formatRupiah($ctx['expense_month']),
            "Tabungan bulan ini: " . $this->formatRupiah($ctx['savings_month']),
        ];

        if (!empty($ctx['budgets'])) {
            $lines[] = "\nBudget bulan ini:";
            foreach ($ctx['budgets'] as $b) {
                $spentFmt = $this->formatRupiah($b['spent']);
                $limitFmt = $this->formatRupiah($b['limit']);
                $lines[] = "  - {$b['category']}: {$spentFmt} / {$limitFmt} ({$b['percent']}%)";
            }
        }

        if (!empty($ctx['accounts'])) {
            $lines[] = "\nAkun keuangan (saldo):";
            foreach ($ctx['accounts'] as $a) {
                $balFmt = $this->formatRupiah($a['balance']);
                $lines[] = "  - {$a['name']}: {$balFmt}";
            }
        }

        if (!empty($ctx['goals'])) {
            $lines[] = "\nGoals aktif:";
            foreach ($ctx['goals'] as $g) {
                $curFmt = $this->formatRupiah($g['current']);
                $tgtFmt = $this->formatRupiah($g['target']);
                $lines[] = "  - {$g['name']}: {$curFmt} / {$tgtFmt} ({$g['percent']}%)";
            }
        }

        return implode("\n", $lines);
    }

    private function pageLabel(string $page, string $lang): string
    {
        if ($lang === 'en') {
            return match ($page) {
                'dashboard'  => 'Dashboard',
                'categories' => 'Categories',
                'budgets'    => 'Budgets',
                'accounts'   => 'Accounts',
                'goals'      => 'Savings Goals',
                default      => ucfirst($page),
            };
        }

        return match ($page) {
            'dashboard'  => 'Dashboard',
            'categories' => 'Kategori',
            'budgets'    => 'Anggaran',
            'accounts'   => 'Akun',
            'goals'      => 'Target Tabungan',
            default      => ucfirst($page),
        };
    }

    private function providerLabel(string $provider, string $model): string
    {
        $names = [
            'openai'   => 'OpenAI',
            'gemini'   => 'Gemini',
            'deepseek' => 'DeepSeek',
            'kimi'     => 'Kimi',
            'claude'   => 'Claude',
            'groq'     => 'Groq',
        ];
        $name = $names[$provider] ?? ucfirst($provider);
        $shortModel = $model ? " ({$model})" : '';
        return "{$name}{$shortModel}";
    }

    private function formatRupiah(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}
