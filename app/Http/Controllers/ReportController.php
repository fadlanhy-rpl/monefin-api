<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\GamificationService;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(
        private GamificationService $gamification,
        private ReportExportService $exportService
    ) {}

    /**
     * GET /api/reports/compare
     * Perbandingan income, expense, savings antar bulan.
     *
     * Query params:
     *   - start_month: YYYY-MM
     *   - end_month:   YYYY-MM
     *   - months:      jumlah bulan ke belakang (default 6, jika tidak ada start/end)
     */
    public function compare(Request $request): JsonResponse
    {
        $user = $request->user();

        // Rekam aksi misi evaluasi / review laporan finansial (maksimal 1x per hari per user)
        $questRecordedTodayKey = "quest_recorded:{$user->id}:check_analytics:" . now()->toDateString();
        if (!Cache::has($questRecordedTodayKey)) {
            $this->gamification->recordQuestAction($user, 'check_analytics', 1);
            Cache::put($questRecordedTodayKey, true, now()->endOfDay());
        }

        if ($request->start_month && $request->end_month) {
            [$startYear, $startMonth] = explode('-', $request->start_month);
            [$endYear,   $endMonth]   = explode('-', $request->end_month);
            $start = \Carbon\Carbon::create((int) $startYear, (int) $startMonth, 1)->startOfMonth();
            $end   = \Carbon\Carbon::create((int) $endYear,   (int) $endMonth,   1)->endOfMonth();
        } else {
            $months = max(1, (int) ($request->months ?? 6));
            $end    = now()->endOfMonth();
            $start  = now()->subMonths($months - 1)->startOfMonth();
        }

        $driver = DB::getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', transaction_date)"
            : "TO_CHAR(transaction_date, 'YYYY-MM')";

        $rows = Transaction::where('user_id', $user->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("{$monthExpr} AS month, type, SUM(amount) AS total")
            ->groupByRaw("{$monthExpr}, type")
            ->orderByRaw("{$monthExpr} ASC")
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $data[$row->month] ??= ['month' => $row->month, 'income' => 0, 'expense' => 0, 'savings' => 0];
            $data[$row->month][$row->type] += (float) $row->total;
        }

        // Fill empty months so chart doesn't break
        $current = $start->copy();
        while ($current <= $end) {
            $key = $current->format('Y-m');
            $data[$key] ??= ['month' => $key, 'income' => 0, 'expense' => 0, 'savings' => 0];
            $current->addMonth();
        }

        $result = collect($data)
            ->map(fn ($row) => array_merge($row, [
                'savings'  => $row['income'] - $row['expense'],
                'cashflow' => $row['income'] - $row['expense'],
            ]))
            ->sortBy('month')
            ->values();

        // Period-level summary stats
        $totalIncome  = $result->sum('income');
        $totalExpense = $result->sum('expense');
        $netSavings   = $totalIncome - $totalExpense;
        $savingRate   = $totalIncome > 0 ? round(($netSavings / $totalIncome) * 100, 1) : 0;

        return response()->json([
            'data' => $result,
            'summary' => [
                'total_income'  => $totalIncome,
                'total_expense' => $totalExpense,
                'net_savings'   => $netSavings,
                'saving_rate'   => $savingRate,
                'period_months' => $result->count(),
            ],
        ]);
    }

    /**
     * GET /api/reports/category-breakdown
     * Distribusi pengeluaran/pemasukan per kategori untuk donut chart.
     *
     * Query params: start_date, end_date, type (default: expense)
     */
    public function categoryBreakdown(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->input('type', 'expense');

        $query = Transaction::where('user_id', $user->id)
            ->where('type', $type)
            ->with('category:id,name,icon,color');

        if ($request->start_date) {
            try {
                $startDate = \Carbon\Carbon::parse($request->start_date)->toDateString();
                $query->where('transaction_date', '>=', $startDate);
            } catch (\Throwable) {}
        }
        if ($request->end_date) {
            try {
                $endDate = \Carbon\Carbon::parse($request->end_date)->toDateString();
                $query->where('transaction_date', '<=', $endDate);
            } catch (\Throwable) {}
        }

        $rows = $query
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->get();

        $grandTotal = $rows->sum('total');

        $breakdown = $rows->map(fn ($row) => [
            'category_id'    => $row->category_id,
            'category_name'  => $row->category?->name ?? 'Lain-lain',
            'category_icon'  => $row->category?->icon ?? null,
            'category_color' => $row->category?->color ?? null,
            'total'          => (float) $row->total,
            'percentage'     => $grandTotal > 0 ? round((float) $row->total / $grandTotal * 100, 1) : 0,
        ])->values();

        return response()->json([
            'data'        => $breakdown,
            'grand_total' => (float) $grandTotal,
            'type'        => $type,
        ]);
    }

    /**
     * GET /api/reports/export
     * Export laporan keuangan ke Excel (.xlsx) profesional — 4 Sheet.
     */
    public function export(Request $request): Response
    {
        return $this->exportService->exportToExcel($request->user(), $request->all());
    }
}