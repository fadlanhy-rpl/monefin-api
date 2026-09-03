<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\SpendingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
     *
     * Cache: 3 menit per user + range (di-invalidate saat transaksi baru).
     * Custom date range tidak di-cache (kombinasi tak terbatas).
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $range     = $request->query('range', '30days');
        $startDate = null;
        $endDate   = now()->endOfDay();

        $hasCustomRange = $request->filled('start_date') && $request->filled('end_date');

        if ($hasCustomRange) {
            $startDate = \Carbon\Carbon::parse($request->query('start_date'))->startOfDay();
            $endDate   = \Carbon\Carbon::parse($request->query('end_date'))->endOfDay();
        } elseif ($range === '7days') {
            $startDate = now()->subDays(7)->startOfDay();
        } elseif ($range === '30days') {
            $startDate = now()->subDays(30)->startOfDay();
        } elseif ($range === 'this_month') {
            $startDate = now()->startOfMonth();
            $endDate   = now()->endOfMonth();
        } elseif ($range === 'this_year') {
            $startDate = now()->startOfYear();
            $endDate   = now()->endOfYear();
        } else {
            $startDate = now()->subDays(30)->startOfDay();
        }

        $lang = $request->header('Accept-Language') ?? ($user->preferences['language'] ?? 'id');

        // Custom date range tidak di-cache (kombinasi tak terbatas)
        // Range preset di-cache 3 menit — di-invalidate setiap ada transaksi baru
        // Cache key harus match dengan key yang di-invalidate di ProcessTransactionSideEffects job
        $cacheKey = "dashboard_summary:{$user->id}:{$range}";

        $computeSummary = fn () => $this->computeSummary($user, $startDate, $endDate, $lang);

        $data = $hasCustomRange
            ? $computeSummary()
            : Cache::remember($cacheKey, 180, $computeSummary);

        return response()->json(['data' => $data]);
    }

    /**
     * Hitung seluruh data dashboard. Dipisahkan agar bisa dibungkus cache.
     */
    private function computeSummary(\App\Models\User $user, $startDate, $endDate, string $lang): array
    {
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
        $spendingStatus = $this->spending->analyze($user, $lang);

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
            ->values()
            ->toArray();  // ← wajib: cache harus menyimpan plain array, bukan Collection

        // 5. Transaksi terbaru (5 terakhir)
        $recentTransactions = Transaction::where('user_id', $user->id)
            ->with(['account:id,name,type', 'category:id,name,icon,type'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->toArray();  // ← wajib: serialisasi model Eloquent ke plain array untuk cache


        // 6. Weekly Trend (Sen - Min) - Single aggregated query for this week and last week
        $startOfThisWeek = now()->startOfWeek();
        $endOfThisWeek   = now()->endOfWeek();
        $startOfLastWeek = now()->subWeek()->startOfWeek();
        $endOfLastWeek   = now()->subWeek()->endOfWeek();

        $thisWeekGrouped = [];
        foreach (
            Transaction::where('user_id', $user->id)
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$startOfThisWeek->toDateString(), $endOfThisWeek->toDateString()])
                ->selectRaw('transaction_date, SUM(amount) as total')
                ->groupBy('transaction_date')
                ->get() as $row
        ) {
            $dow = \Carbon\Carbon::parse($row->transaction_date)->dayOfWeek + 1;
            $thisWeekGrouped[$dow] = ($thisWeekGrouped[$dow] ?? 0) + (float) $row->total;
        }

        $lastWeekGrouped = [];
        foreach (
            Transaction::where('user_id', $user->id)
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$startOfLastWeek->toDateString(), $endOfLastWeek->toDateString()])
                ->selectRaw('transaction_date, SUM(amount) as total')
                ->groupBy('transaction_date')
                ->get() as $row
        ) {
            $dow = \Carbon\Carbon::parse($row->transaction_date)->dayOfWeek + 1;
            $lastWeekGrouped[$dow] = ($lastWeekGrouped[$dow] ?? 0) + (float) $row->total;
        }

        $daysMap     = [2 => 'Sen', 3 => 'Sel', 4 => 'Rab', 5 => 'Kam', 6 => 'Jum', 7 => 'Sab', 1 => 'Min'];
        $weeklyTrend = [];
        foreach ([2, 3, 4, 5, 6, 7, 1] as $idx) {
            $thisAmt = (float) ($thisWeekGrouped[$idx] ?? 0);
            $lastAmt = (float) ($lastWeekGrouped[$idx] ?? 0);
            $max     = max($thisAmt, $lastAmt, 1);

            $weeklyTrend[] = [
                'label'    => $daysMap[$idx],
                'thisAmt'  => $thisAmt,
                'lastAmt'  => $lastAmt,
                'thisWeek' => round(($thisAmt / $max) * 100),
                'last'     => round(($lastAmt / $max) * 100),
            ];
        }

        // 7. Monthly Trend (Past 6 months) - Optimized single query per period
        $driver    = DB::getDriverName();
        $monthExpr = match ($driver) {
            'sqlite' => "strftime('%Y-%m', transaction_date)",
            'pgsql'  => "TO_CHAR(transaction_date, 'YYYY-MM')",
            default  => "DATE_FORMAT(transaction_date, '%Y-%m')",
        };

        $startMonth         = now()->subMonths(5)->startOfMonth();
        $endMonth           = now()->endOfMonth();
        $startMonthLastYear = now()->subMonths(5)->subYear()->startOfMonth();
        $endMonthLastYear   = now()->subYear()->endOfMonth();

        $thisYearMonthSums = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startMonth->toDateString(), $endMonth->toDateString()])
            ->selectRaw("{$monthExpr} as period, SUM(amount) as total")
            ->groupByRaw($monthExpr)
            ->pluck('total', 'period')
            ->toArray();

        $lastYearMonthSums = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startMonthLastYear->toDateString(), $endMonthLastYear->toDateString()])
            ->selectRaw("{$monthExpr} as period, SUM(amount) as total")
            ->groupByRaw($monthExpr)
            ->pluck('total', 'period')
            ->toArray();

        $monthlyTrend = [];
        for ($i = 0; $i < 6; $i++) {
            $currentDate       = $startMonth->copy()->addMonths($i);
            $periodKeyThisYear = $currentDate->format('Y-m');
            $periodKeyLastYear = $currentDate->copy()->subYear()->format('Y-m');

            $m          = $currentDate->month;
            $monthLabel = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][$m - 1];

            $thisAmt = (float) ($thisYearMonthSums[$periodKeyThisYear] ?? 0);
            $lastAmt = (float) ($lastYearMonthSums[$periodKeyLastYear] ?? 0);
            $max     = max($thisAmt, $lastAmt, 1);

            $monthlyTrend[] = [
                'label'    => $monthLabel,
                'thisAmt'  => $thisAmt,
                'lastAmt'  => $lastAmt,
                'thisWeek' => round(($thisAmt / $max) * 100),
                'last'     => round(($lastAmt / $max) * 100),
            ];
        }

        return [
            'weekly_trend'             => $weeklyTrend,
            'monthly_trend'            => $monthlyTrend,
            'total_balance'            => (float) $totalBalance,
            'total_income_this_month'  => (float) $totalIncomeThisMonth,
            'total_expense_this_month' => (float) $totalExpenseThisMonth,
            'savings_this_month'       => (float) ($totalIncomeThisMonth - $totalExpenseThisMonth),
            'spending_status'          => $spendingStatus,
            'expense_by_category'      => $expenseByCategory,
            'recent_transactions'      => $recentTransactions,
        ];
    }
}
