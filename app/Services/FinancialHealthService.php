<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

/**
 * Deterministic financial health scoring — no AI required.
 * Fully localized for 'id' and 'en'.
 *
 * Scoring Formula (0–100):
 *   30% — Cashflow Ratio   (income vs expense)
 *   25% — Budget Compliance (how well user stays under limits)
 *   20% — Savings Rate      (savings as % of income)
 *   15% — Goals Progress    (average completion % of active goals)
 *   10% — Recording Consistency (days with at least one transaction in last 30 days)
 */
class FinancialHealthService
{
    public function insights(User $user, string $lang = 'id'): array
    {
        $lang = str_starts_with(strtolower($lang), 'en') ? 'en' : 'id';

        $now        = Carbon::now();
        $startMonth = $now->copy()->startOfMonth()->toDateString();
        $endMonth   = $now->copy()->endOfMonth()->toDateString();
        $startWeek  = $now->copy()->startOfWeek()->toDateString();
        $endWeek    = $now->copy()->endOfWeek()->toDateString();
        $last30     = $now->copy()->subDays(30)->toDateString();
        $lastWeek   = $now->copy()->subWeek();

        // ── Raw data ──────────────────────────────────────────────────────────
        $incomeMonth   = (float) Transaction::where('user_id', $user->id)->where('type', 'income')->whereBetween('transaction_date', [$startMonth, $endMonth])->sum('amount');
        $expenseMonth  = (float) Transaction::where('user_id', $user->id)->where('type', 'expense')->whereBetween('transaction_date', [$startMonth, $endMonth])->sum('amount');
        $expenseWeek   = (float) Transaction::where('user_id', $user->id)->where('type', 'expense')->whereBetween('transaction_date', [$startWeek, $endWeek])->sum('amount');
        $expenseLastWk = (float) Transaction::where('user_id', $user->id)->where('type', 'expense')
            ->whereBetween('transaction_date', [
                $lastWeek->copy()->startOfWeek()->toDateString(),
                $lastWeek->copy()->endOfWeek()->toDateString(),
            ])->sum('amount');

        $savings = $incomeMonth - $expenseMonth;

        // Top categories (last 30 days)
        $defaultCatName = $lang === 'en' ? 'Others' : 'Lain-lain';
        $topCategories = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$last30, $now->toDateString()])
            ->with('category:id,name')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'category' => $r->category?->name ?? $defaultCatName,
                'amount'   => (float) $r->total,
            ])
            ->toArray();

        // Budgets this month
        $budgets = Budget::where('user_id', $user->id)
            ->where('month', $now->month)
            ->where('year', $now->year)
            ->with('category:id,name')
            ->get()
            ->map(function ($b) use ($user, $now, $defaultCatName) {
                $spent = (float) Transaction::where('user_id', $user->id)
                    ->where('category_id', $b->category_id)
                    ->where('type', 'expense')
                    ->whereMonth('transaction_date', $now->month)
                    ->whereYear('transaction_date', $now->year)
                    ->sum('amount');
                return [
                    'category' => $b->category?->name ?? $defaultCatName,
                    'limit'    => (float) $b->limit_amount,
                    'spent'    => $spent,
                    'percent'  => $b->limit_amount > 0 ? round(($spent / $b->limit_amount) * 100) : 0,
                ];
            })
            ->toArray();

        // Goals
        $goals = $user->goals()
            ->whereColumn('current_amount', '<', 'target_amount')
            ->get(['name', 'target_amount', 'current_amount'])
            ->map(fn($g) => [
                'name'    => $g->name,
                'target'  => (float) $g->target_amount,
                'current' => (float) $g->current_amount,
                'percent' => $g->target_amount > 0 ? min(100, round(($g->current_amount / $g->target_amount) * 100)) : 0,
            ])
            ->toArray();

        // Recording consistency: distinct days with transactions in last 30 days
        $activeDays = Transaction::where('user_id', $user->id)
            ->whereBetween('transaction_date', [$last30, $now->toDateString()])
            ->distinct('transaction_date')
            ->count('transaction_date');

        // ── Scoring ───────────────────────────────────────────────────────────
        $cashflowScore    = $this->scoreCashflow($incomeMonth, $expenseMonth);       // 0-100
        $budgetScore      = $this->scoreBudget($budgets);                            // 0-100
        $savingsScore     = $this->scoreSavings($incomeMonth, $savings);             // 0-100
        $goalsScore       = $this->scoreGoals($goals);                               // 0-100
        $consistencyScore = min(100, ($activeDays / 30) * 100);                    // 0-100

        $total = round(
            $cashflowScore    * 0.30 +
            $budgetScore      * 0.25 +
            $savingsScore     * 0.20 +
            $goalsScore       * 0.15 +
            $consistencyScore * 0.10
        );

        $total = max(0, min(100, $total));

        // ── Labels & Summary ──────────────────────────────────────────────────
        [$scoreLabel, $summary, $positiveNote] = $this->buildLabels($total, $incomeMonth, $expenseMonth, $savings, $expenseWeek, $expenseLastWk, $lang);

        // ── Tips (deterministic, data-driven) ────────────────────────────────
        $tips = $this->buildTips($incomeMonth, $expenseMonth, $savings, $budgets, $goals, $activeDays, $topCategories, $lang);

        return [
            'health_score'   => $total,
            'score_label'    => $scoreLabel,
            'weekly_summary' => $summary,
            'tips'           => $tips,
            'positive_note'  => $positiveNote,
            'source'         => 'engine', // Always deterministic
        ];
    }

    // ─── Scoring Helpers ──────────────────────────────────────────────────────

    private function scoreCashflow(float $income, float $expense): float
    {
        if ($income <= 0) {
            return $expense > 0 ? 0 : 40;
        }
        $ratio = ($income - $expense) / $income;
        return min(100, max(0, $ratio * 100));
    }

    private function scoreBudget(array $budgets): float
    {
        if (empty($budgets)) {
            return 50;
        }
        $scores = array_map(function ($b) {
            return min(100, max(0, 100 - max(0, $b['percent'] - 100)));
        }, $budgets);

        return array_sum($scores) / count($scores);
    }

    private function scoreSavings(float $income, float $savings): float
    {
        if ($income <= 0) {
            return 50;
        }
        $rate = ($savings / $income) * 100;
        return min(100, max(0, ($rate / 20) * 100));
    }

    private function scoreGoals(array $goals): float
    {
        if (empty($goals)) {
            return 50;
        }
        $percents = array_column($goals, 'percent');
        return array_sum($percents) / count($percents);
    }

    // ─── Label & Narrative Builders ───────────────────────────────────────────

    private function buildLabels(float $score, float $income, float $expense, float $savings, float $expenseWeek, float $expenseLastWk, string $lang): array
    {
        $incomeFmt  = $this->formatRupiah($income);
        $expenseFmt = $this->formatRupiah($expense);
        $savingsFmt = $this->formatRupiah(max(0, $savings));

        if ($lang === 'en') {
            if ($score >= 80) {
                $label   = 'Excellent';
                $summary = "Your finances are in excellent condition! Monthly income {$incomeFmt} with savings of {$savingsFmt}. Keep up these great habits.";
                $note    = "Your consistency in managing money is commendable — a score above 80 is achieved by fewer than 20% of users!";
            } elseif ($score >= 60) {
                $label   = 'Healthy';
                $summary = "Your finances are considered healthy. Income {$incomeFmt}, expenses {$expenseFmt}. There is room to increase your savings further.";
                $note    = "You're on the right track. Minor optimizations in non-essential spending can boost your score to 'Excellent'.";
            } elseif ($score >= 40) {
                $label   = 'Fair';
                $summary = "Your financial status is fairly stable, but a few areas need attention. Expenses this month {$expenseFmt} from income of {$incomeFmt}.";
                $note    = "Best next step: create at least 1 category budget and 1 savings goal to improve your score significantly.";
            } elseif ($score >= 20) {
                $label   = 'Needs Attention';
                $summary = "Your finances need closer attention. " . ($expense > $income && $income > 0 ? "Expenses ({$expenseFmt}) exceed income ({$incomeFmt}) this month." : "Record income and expenses regularly for a more accurate financial picture.");
                $note    = "Start small: record 1 transaction every day for a week and see the difference!";
            } else {
                $label   = 'Critical';
                $summary = "Your finances need immediate evaluation. " . ($income <= 0 ? "No income recorded yet." : "Expenses far exceed income this month.");
                $note    = "Don't worry — every small improvement counts. Start by recording your expenses today.";
            }
        } else {
            if ($score >= 80) {
                $label   = 'Sangat Sehat';
                $summary = "Keuangan Anda dalam kondisi sangat prima! Pemasukan bulan ini {$incomeFmt} dengan tabungan {$savingsFmt}. Pertahankan kebiasaan baik ini.";
                $note    = "Konsistensi Anda dalam mengelola keuangan patut diapresiasi — skor di atas 80 dicapai oleh kurang dari 20% pengguna!";
            } elseif ($score >= 60) {
                $label   = 'Sehat';
                $summary = "Keuangan Anda tergolong sehat. Pemasukan {$incomeFmt}, pengeluaran {$expenseFmt}. Ada ruang untuk meningkatkan tabungan lebih jauh.";
                $note    = "Anda sudah di jalur yang tepat. Sedikit optimasi pada pengeluaran non-esensial bisa mendorong skor Anda ke 'Sangat Sehat'.";
            } elseif ($score >= 40) {
                $label   = 'Cukup';
                $summary = "Kondisi keuangan Anda cukup stabil, namun ada beberapa area yang perlu perhatian. Pengeluaran bulan ini {$expenseFmt} dari pemasukan {$incomeFmt}.";
                $note    = "Langkah terbaik sekarang: buat minimal 1 anggaran kategori dan 1 target tabungan untuk meningkatkan skor secara signifikan.";
            } elseif ($score >= 20) {
                $label   = 'Perlu Perhatian';
                $summary = "Keuangan Anda membutuhkan perhatian lebih. " . ($expense > $income && $income > 0 ? "Pengeluaran ({$expenseFmt}) melebihi pemasukan ({$incomeFmt}) bulan ini." : "Catat pemasukan dan pengeluaran secara rutin untuk gambaran yang lebih akurat.");
                $note    = "Mulai dari hal kecil: catat 1 transaksi setiap hari selama seminggu dan lihat perbedaannya!";
            } else {
                $label   = 'Kritis';
                $summary = "Kondisi keuangan Anda perlu segera dievaluasi. " . ($income <= 0 ? "Belum ada pemasukan yang tercatat." : "Pengeluaran jauh melampaui pemasukan bulan ini.");
                $note    = "Jangan khawatir — setiap perbaikan sekecil apapun sangat berarti. Mulai dengan mencatat pengeluaran hari ini.";
            }
        }

        return [$label, $summary, $note];
    }

    private function buildTips(float $income, float $expense, float $savings, array $budgets, array $goals, int $activeDays, array $topCategories, string $lang): array
    {
        $tips = [];

        // Priority 1 — Cashflow alert
        if ($income > 0 && $expense > $income) {
            $expenseFmt = $this->formatRupiah($expense);
            $incomeFmt  = $this->formatRupiah($income);
            $tips[] = [
                'type'         => 'alert',
                'title'        => $lang === 'en' ? 'Expenses Exceed Income' : 'Pengeluaran Melebihi Pemasukan',
                'body'         => $lang === 'en'
                    ? "This month's expenses ({$expenseFmt}) exceed income ({$incomeFmt}). Review non-essential expenses promptly."
                    : "Pengeluaran bulan ini ({$expenseFmt}) melebihi pemasukan ({$incomeFmt}). Evaluasi pengeluaran non-esensial segera.",
                'action_label' => $lang === 'en' ? 'View Transactions' : 'Lihat Transaksi',
                'action_url'   => '/transactions',
            ];
        }

        // Priority 2 — Budget nearing limit
        $criticalBudgets = array_filter($budgets, fn($b) => $b['percent'] >= 85);
        if (!empty($criticalBudgets)) {
            $worst = array_reduce($criticalBudgets, fn($carry, $b) => (!$carry || $b['percent'] > $carry['percent']) ? $b : $carry, null);
            if ($worst) {
                $categoryName = $worst['category'];
                $percentValue = $worst['percent'];
                $tips[] = [
                    'type'         => 'budget',
                    'title'        => $lang === 'en' ? "Budget '{$categoryName}' Almost Depleted" : "Anggaran '{$categoryName}' Hampir Habis",
                    'body'         => $lang === 'en'
                        ? "Category '{$categoryName}' has reached {$percentValue}% of its budget limit. Consider trimming spending here."
                        : "Kategori '{$categoryName}' sudah mencapai {$percentValue}% dari anggaran. Pertimbangkan untuk mengurangi pengeluaran di kategori ini.",
                    'action_label' => $lang === 'en' ? 'View Budgets' : 'Lihat Budget',
                    'action_url'   => '/budgets',
                ];
            }
        }

        // Priority 3 — Low savings rate
        if ($income > 0) {
            $savingsRate = ($savings / $income) * 100;
            if ($savingsRate < 10 && $savings >= 0) {
                $targetSavingFmt = $this->formatRupiah($income * 0.20);
                $roundedRate     = round($savingsRate);
                $tips[] = [
                    'type'         => 'saving',
                    'title'        => $lang === 'en' ? 'Boost Your Savings Rate' : 'Tingkatkan Rasio Tabungan',
                    'body'         => $lang === 'en'
                        ? "Your savings rate is currently {$roundedRate}%. Ideal target is at least 20% of income ({$targetSavingFmt}/month)."
                        : "Rasio tabungan Anda saat ini {$roundedRate}%. Target ideal minimal 20% dari pemasukan ({$targetSavingFmt}/bulan).",
                    'action_label' => $lang === 'en' ? 'Create Goals' : 'Buat Goals',
                    'action_url'   => '/goals',
                ];
            }
        }

        // Priority 4 — No goals
        if (empty($goals)) {
            $tips[] = [
                'type'         => 'goal',
                'title'        => $lang === 'en' ? 'Set Your First Financial Goal' : 'Buat Target Keuangan Pertama',
                'body'         => $lang === 'en'
                    ? "Users with active savings goals tend to save 3× more consistently. Start setting your first target today!"
                    : "Pengguna yang memiliki goals tabungan cenderung menabung 3× lebih konsisten. Mulai buat target pertama Anda sekarang!",
                'action_label' => $lang === 'en' ? 'Create Goals' : 'Buat Goals',
                'action_url'   => '/goals',
            ];
        }

        // Priority 5 — Inconsistent recording
        if ($activeDays < 10) {
            $tips[] = [
                'type'         => 'expense',
                'title'        => $lang === 'en' ? 'Record Transactions Regularly' : 'Catat Transaksi Lebih Rutin',
                'body'         => $lang === 'en'
                    ? "You have recorded transactions on {$activeDays} days in the last 30 days. Daily tracking improves analysis accuracy."
                    : "Anda baru mencatat transaksi {$activeDays} hari dalam 30 hari terakhir. Pencatatan rutin meningkatkan akurasi analisis keuangan Anda.",
                'action_label' => $lang === 'en' ? 'Record Transaction' : 'Catat Transaksi',
                'action_url'   => '/transactions',
            ];
        }

        // Priority 6 — Top spending category tip
        if (!empty($topCategories) && count($tips) < 3) {
            $top            = $topCategories[0];
            $categoryName   = $top['category'];
            $topAmountFmt   = $this->formatRupiah($top['amount']);
            $tips[] = [
                'type'         => 'expense',
                'title'        => $lang === 'en' ? "Largest Expense: {$categoryName}" : "Pengeluaran Terbesar: {$categoryName}",
                'body'         => $lang === 'en'
                    ? "Category '{$categoryName}' is your largest expense in the last 30 days at {$topAmountFmt}. Keep an eye on this."
                    : "Kategori '{$categoryName}' adalah pengeluaran terbesar Anda 30 hari terakhir sebesar {$topAmountFmt}. Pantau tren ini.",
                'action_label' => $lang === 'en' ? 'View Reports' : 'Lihat Laporan',
                'action_url'   => '/reports',
            ];
        }

        // Priority 7 — No budget set
        if (empty($budgets) && count($tips) < 3) {
            $tips[] = [
                'type'         => 'budget',
                'title'        => $lang === 'en' ? 'Set Monthly Budgets' : 'Atur Anggaran Bulanan',
                'body'         => $lang === 'en'
                    ? "No active category budgets this month. Setting budget limits helps you curb unnecessary expenses."
                    : "Belum ada anggaran aktif bulan ini. Membuat budget kategori membantu mengontrol pengeluaran secara efektif.",
                'action_label' => $lang === 'en' ? 'Create Budget' : 'Buat Budget',
                'action_url'   => '/budgets',
            ];
        }

        return array_slice($tips, 0, 3);
    }

    private function formatRupiah(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}
