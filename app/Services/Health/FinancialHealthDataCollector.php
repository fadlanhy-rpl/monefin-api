<?php

namespace App\Services\Health;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class FinancialHealthDataCollector
{
    /**
     * Mengumpulkan data finansial mentah pengguna secara efisien (tanpa N+1 query).
     */
    public function collect(User $user, string $lang = 'id'): array
    {
        $now        = Carbon::now();
        $startMonth = $now->copy()->startOfMonth()->toDateString();
        $endMonth   = $now->copy()->endOfMonth()->toDateString();
        $startWeek  = $now->copy()->startOfWeek()->toDateString();
        $endWeek    = $now->copy()->endOfWeek()->toDateString();
        $last30     = $now->copy()->subDays(30)->toDateString();
        $lastWeek   = $now->copy()->subWeek();

        // ── Agregasi pemasukan & pengeluaran ──────────────────────────────────
        $incomeMonth = (float) Transaction::where('user_id', $user->id)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$startMonth, $endMonth])
            ->sum('amount');

        $expenseMonth = (float) Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startMonth, $endMonth])
            ->sum('amount');

        $expenseWeek = (float) Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startWeek, $endWeek])
            ->sum('amount');

        $expenseLastWk = (float) Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [
                $lastWeek->copy()->startOfWeek()->toDateString(),
                $lastWeek->copy()->endOfWeek()->toDateString(),
            ])
            ->sum('amount');

        $savings = $incomeMonth - $expenseMonth;

        // ── Top categories (last 30 days) ─────────────────────────────────────
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

        // ── Budgets bulan ini (Batch query pengeluaran untuk cegah N+1) ───────
        $rawBudgets = Budget::where('user_id', $user->id)
            ->where('month', $now->month)
            ->where('year', $now->year)
            ->with('category:id,name')
            ->get();

        $categoryIds = $rawBudgets->pluck('category_id')->filter()->unique()->toArray();
        $spentMap = [];
        if (!empty($categoryIds)) {
            $spentMap = Transaction::where('user_id', $user->id)
                ->whereIn('category_id', $categoryIds)
                ->where('type', 'expense')
                ->whereMonth('transaction_date', $now->month)
                ->whereYear('transaction_date', $now->year)
                ->groupBy('category_id')
                ->selectRaw('category_id, SUM(amount) as total_spent')
                ->pluck('total_spent', 'category_id')
                ->toArray();
        }

        $budgets = $rawBudgets->map(function ($b) use ($spentMap, $defaultCatName) {
            $spent = (float) ($spentMap[$b->category_id] ?? 0);
            return [
                'category' => $b->category?->name ?? $defaultCatName,
                'limit'    => (float) $b->limit_amount,
                'spent'    => $spent,
                'percent'  => $b->limit_amount > 0 ? round(($spent / $b->limit_amount) * 100) : 0,
            ];
        })->toArray();

        // ── Goals ─────────────────────────────────────────────────────────────
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

        // ── Konsistensi pencatatan: distinct hari transaksi 30 hari terakhir ──
        $activeDays = Transaction::where('user_id', $user->id)
            ->whereBetween('transaction_date', [$last30, $now->toDateString()])
            ->distinct('transaction_date')
            ->count('transaction_date');

        return [
            'income_month'   => $incomeMonth,
            'expense_month'  => $expenseMonth,
            'expense_week'   => $expenseWeek,
            'expense_last_wk'=> $expenseLastWk,
            'savings'        => $savings,
            'top_categories' => $topCategories,
            'budgets'        => $budgets,
            'goals'          => $goals,
            'active_days'    => $activeDays,
        ];
    }
}
