<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\SpendingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private SpendingAnalysisService $spending) {}

    /**
     * GET /api/dashboard/summary
     * Ringkasan untuk halaman dashboard:
     * - Total saldo semua akun aktif
     * - Total income & expense bulan berjalan
     * - Status hemat/normal/boros
     * - Pengeluaran per kategori (pie chart)
     * - Transaksi terbaru
     */
    public function summary(Request $request): JsonResponse
    {
        $user  = $request->user();

        $range = $request->query('range', '30days');
        $startDate = null;
        $endDate = now()->endOfDay();

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = \Carbon\Carbon::parse($request->query('start_date'))->startOfDay();
            $endDate = \Carbon\Carbon::parse($request->query('end_date'))->endOfDay();
        } elseif ($range === '7days') {
            $startDate = now()->subDays(7)->startOfDay();
        } elseif ($range === '30days') {
            $startDate = now()->subDays(30)->startOfDay();
        } elseif ($range === 'this_month') {
            $startDate = now()->startOfMonth();
            $endDate = now()->endOfMonth();
        } elseif ($range === 'this_year') {
            $startDate = now()->startOfYear();
            $endDate = now()->endOfYear();
        } else {
            $startDate = now()->subDays(30)->startOfDay();
        }

        // 1. Total saldo semua akun aktif (tidak soft-deleted)
        $totalBalance = $user->accounts()->sum('balance');

        // 2. Total income & expense pada rentang tanggal terpilih
        $totalIncomeThisMonth = Transaction::where('user_id', $user->id)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('amount');

        $totalExpenseThisMonth = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('amount');

        // 3. Status hemat/normal/boros
        $spendingStatus = $this->spending->analyze($user);

        // 4. Pengeluaran per kategori pada rentang tanggal terpilih
        $expenseByCategory = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with('category:id,name,icon')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->get()
            ->map(fn ($row) => [
                'category'    => $row->category?->name ?? 'Lain-lain',
                'icon'        => $row->category?->icon,
                'amount'      => (float) $row->total,
                'category_id' => $row->category_id,
            ])
            ->sortByDesc('amount')
            ->values();

        // 5. Transaksi terbaru (5 terakhir)
        $recentTransactions = Transaction::where('user_id', $user->id)
            ->with(['account:id,name,type', 'category:id,name,icon,type'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        // 6. Weekly Trend (Sen - Min) - This week vs Last week
        $startOfThisWeek = now()->startOfWeek();
        $endOfThisWeek   = now()->endOfWeek();
        $startOfLastWeek = now()->subWeek()->startOfWeek();
        $endOfLastWeek   = now()->subWeek()->endOfWeek();

        $thisWeekExpenses = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startOfThisWeek->toDateString(), $endOfThisWeek->toDateString()])
            ->get()
            ->groupBy(function($item) {
                return \Carbon\Carbon::parse($item->transaction_date)->dayOfWeek + 1;
            })
            ->map(function($group) {
                return (object) ['total' => $group->sum('amount')];
            });
            
        $lastWeekExpenses = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startOfLastWeek->toDateString(), $endOfLastWeek->toDateString()])
            ->get()
            ->groupBy(function($item) {
                return \Carbon\Carbon::parse($item->transaction_date)->dayOfWeek + 1;
            })
            ->map(function($group) {
                return (object) ['total' => $group->sum('amount')];
            });

        // MySQL DAYOFWEEK: 1=Sun, 2=Mon, 3=Tue, 4=Wed, 5=Thu, 6=Fri, 7=Sat
        $daysMap = [2 => 'Sen', 3 => 'Sel', 4 => 'Rab', 5 => 'Kam', 6 => 'Jum', 7 => 'Sab', 1 => 'Min'];
        $weeklyTrend = [];
        foreach ([2,3,4,5,6,7,1] as $idx) {
            $thisAmt = (float) ($thisWeekExpenses[$idx]->total ?? 0);
            $lastAmt = (float) ($lastWeekExpenses[$idx]->total ?? 0);
            $max = max($thisAmt, $lastAmt, 1); // prevent division by zero
            
            $weeklyTrend[] = [
                'label'   => $daysMap[$idx],
                'thisAmt' => $thisAmt,
                'lastAmt' => $lastAmt,
                'thisWeek'=> round(($thisAmt / $max) * 100),
                'last'    => round(($lastAmt / $max) * 100),
            ];
        }

        // 7. Monthly Trend (Past 6 months) - This year vs Last year
        $monthlyTrend = [];
        $startMonth = now()->subMonths(5)->startOfMonth();
        for ($i = 0; $i < 6; $i++) {
            $currentDate = $startMonth->copy()->addMonths($i);
            $m = $currentDate->month;
            $y = $currentDate->year;
            $monthLabel = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'][$m-1];
            
            $thisAmt = (float) Transaction::where('user_id', $user->id)
                ->where('type', 'expense')
                ->whereMonth('transaction_date', $m)
                ->whereYear('transaction_date', $y)
                ->sum('amount');
                
            $lastAmt = (float) Transaction::where('user_id', $user->id)
                ->where('type', 'expense')
                ->whereMonth('transaction_date', $m)
                ->whereYear('transaction_date', $y - 1)
                ->sum('amount');
                
            $max = max($thisAmt, $lastAmt, 1);
            $monthlyTrend[] = [
                'label'   => $monthLabel,
                'thisAmt' => $thisAmt,
                'lastAmt' => $lastAmt,
                'thisWeek'=> round(($thisAmt / $max) * 100),
                'last'    => round(($lastAmt / $max) * 100),
            ];
        }

        return response()->json([
            'data' => [
                'weekly_trend'             => $weeklyTrend,
                'monthly_trend'            => $monthlyTrend,
                'total_balance'            => (float) $totalBalance,
                'total_income_this_month'  => (float) $totalIncomeThisMonth,
                'total_expense_this_month' => (float) $totalExpenseThisMonth,
                'savings_this_month'       => (float) ($totalIncomeThisMonth - $totalExpenseThisMonth),
                'spending_status'          => $spendingStatus,
                'expense_by_category'      => $expenseByCategory,
                'recent_transactions'      => $recentTransactions,
            ],
        ]);
    }
}
