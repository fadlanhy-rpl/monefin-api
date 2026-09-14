<?php

namespace App\Services\Insights;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class InsightContextBuilder
{
    public function __construct(private readonly DeterministicRuleEngine $ruleEngine) {}

    public function buildPageContext(User $user, string $page, string $lang): array
    {
        $now        = Carbon::now();
        $startMonth = $now->copy()->startOfMonth()->toDateString();
        $endMonth   = $now->copy()->endOfMonth()->toDateString();
        $startWeek  = $now->copy()->startOfWeek()->toDateString();
        $endWeek    = $now->copy()->endOfWeek()->toDateString();
        $lastWeek   = $now->copy()->subWeek();

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

        if (in_array($page, ['categories', 'budgets', 'dashboard'], true)) {
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

        if (in_array($page, ['accounts'], true)) {
            $ctx['accounts'] = $user->accounts()->get(['id', 'name', 'balance', 'type'])
                ->map(fn($a) => ['name' => $a->name, 'balance' => (float) $a->balance, 'type' => $a->type])
                ->toArray();
        }

        if (in_array($page, ['goals'], true)) {
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

    public function contextToText(array $ctx, string $page): string
    {
        $lines = [
            "Halaman: {$this->pageLabel($page, 'id')}",
            "Pemasukan bulan ini: " . $this->ruleEngine->formatRupiah($ctx['income_month']),
            "Pengeluaran bulan ini: " . $this->ruleEngine->formatRupiah($ctx['expense_month']),
            "Tabungan bulan ini: " . $this->ruleEngine->formatRupiah($ctx['savings_month']),
        ];

        if (!empty($ctx['budgets'])) {
            $lines[] = "\nBudget bulan ini:";
            foreach ($ctx['budgets'] as $b) {
                $spentFmt = $this->ruleEngine->formatRupiah($b['spent']);
                $limitFmt = $this->ruleEngine->formatRupiah($b['limit']);
                $lines[] = "  - {$b['category']}: {$spentFmt} / {$limitFmt} ({$b['percent']}%)";
            }
        }

        if (!empty($ctx['accounts'])) {
            $lines[] = "\nAkun keuangan (saldo):";
            foreach ($ctx['accounts'] as $a) {
                $balFmt = $this->ruleEngine->formatRupiah($a['balance']);
                $lines[] = "  - {$a['name']}: {$balFmt}";
            }
        }

        if (!empty($ctx['goals'])) {
            $lines[] = "\nGoals aktif:";
            foreach ($ctx['goals'] as $g) {
                $curFmt = $this->ruleEngine->formatRupiah($g['current']);
                $tgtFmt = $this->ruleEngine->formatRupiah($g['target']);
                $lines[] = "  - {$g['name']}: {$curFmt} / {$tgtFmt} ({$g['percent']}%)";
            }
        }

        return implode("\n", $lines);
    }

    public function pageLabel(string $page, string $lang): string
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

    public function providerLabel(string $provider, string $model): string
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
}
