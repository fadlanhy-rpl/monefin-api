<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
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
            $query->where('transaction_date', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->where('transaction_date', '<=', $request->end_date);
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
     * Export laporan keuangan ke CSV profesional bergaya data analyst.
     *
     * Query params: start_date, end_date, type, category_id, account_id
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = $request->user();

        $transactions = Transaction::where('user_id', $user->id)
            ->with(['account', 'category'])
            ->when($request->start_date, fn ($q) => $q->where('transaction_date', '>=', $request->start_date))
            ->when($request->end_date,   fn ($q) => $q->where('transaction_date', '<=', $request->end_date))
            ->when($request->type,        fn ($q) => $q->where('type', $request->type))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->account_id,  fn ($q) => $q->where('account_id', $request->account_id))
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        // Compute statistics
        $totalIncome  = $transactions->where('type', 'income')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $netCashflow  = $totalIncome - $totalExpense;
        $savingRate   = $totalIncome > 0 ? round($netCashflow / $totalIncome * 100, 1) : 0;
        $txCount      = $transactions->count();
        $incomeCount  = $transactions->where('type', 'income')->count();
        $expenseCount = $transactions->where('type', 'expense')->count();
        $avgIncome    = $incomeCount  > 0 ? $totalIncome  / $incomeCount  : 0;
        $avgExpense   = $expenseCount > 0 ? $totalExpense / $expenseCount : 0;
        $maxExpense   = $transactions->where('type', 'expense')->max('amount') ?? 0;
        $maxIncome    = $transactions->where('type', 'income')->max('amount') ?? 0;

        // Category breakdown for section 4
        $categoryStats = $transactions
            ->groupBy(fn ($t) => $t->category?->name ?? 'Lain-lain')
            ->map(fn ($group, $catName) => [
                'name'  => $catName,
                'count' => $group->count(),
                'total' => $group->sum('amount'),
                'type'  => $group->first()->type,
            ])
            ->sortByDesc('total')
            ->values();

        $periodLabel = ($request->start_date && $request->end_date)
            ? "{$request->start_date} s/d {$request->end_date}"
            : 'Semua Periode';

        $filename = 'MoneFin_LaporanKeuangan_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use (
            $transactions, $categoryStats,
            $totalIncome, $totalExpense, $netCashflow, $savingRate,
            $txCount, $incomeCount, $expenseCount, $avgIncome, $avgExpense,
            $maxIncome, $maxExpense, $periodLabel
        ) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM — for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // =========================================================
            // SECTION 1: Cover / Title
            // =========================================================
            fputcsv($handle, ['LAPORAN KEUANGAN PERSONAL — MoneFin']);
            fputcsv($handle, ['Dibuat pada',       now()->format('d F Y, H:i') . ' WIB']);
            fputcsv($handle, ['Periode Laporan',   $periodLabel]);
            fputcsv($handle, ['Sumber',            'MoneFin Financial Analytics System v1.0']);
            fputcsv($handle, ['Catatan',           'Laporan ini bersifat rahasia. Hanya untuk penggunaan pribadi.']);
            fputcsv($handle, []);

            // =========================================================
            // SECTION 2: Ringkasan Eksekutif
            // =========================================================
            fputcsv($handle, ['=== RINGKASAN EKSEKUTIF ===']);
            fputcsv($handle, ['Metrik', 'Nilai', 'Keterangan']);
            fputcsv($handle, ['Total Pemasukan',   'Rp ' . number_format($totalIncome,  0, ',', '.'), 'Total income dalam periode']);
            fputcsv($handle, ['Total Pengeluaran', 'Rp ' . number_format($totalExpense, 0, ',', '.'), 'Total expense dalam periode']);
            fputcsv($handle, ['Net Cashflow',      'Rp ' . number_format($netCashflow,  0, ',', '.'), $netCashflow >= 0 ? 'SURPLUS - Keuangan Sehat' : 'DEFISIT - Perlu Evaluasi']);
            fputcsv($handle, ['Saving Rate',       $savingRate . '%',                                  $savingRate >= 20 ? 'BAIK (target >= 20%)' : 'PERLU DITINGKATKAN (< 20%)']);
            fputcsv($handle, []);

            // =========================================================
            // SECTION 3: Statistik Transaksi
            // =========================================================
            fputcsv($handle, ['=== STATISTIK TRANSAKSI ===']);
            fputcsv($handle, ['Metrik', 'Nilai']);
            fputcsv($handle, ['Total Transaksi',              $txCount . ' transaksi']);
            fputcsv($handle, ['Transaksi Pemasukan',          $incomeCount . ' transaksi']);
            fputcsv($handle, ['Transaksi Pengeluaran',        $expenseCount . ' transaksi']);
            fputcsv($handle, ['Rata-rata Pemasukan / Transaksi',  'Rp ' . number_format($avgIncome,  0, ',', '.')]);
            fputcsv($handle, ['Rata-rata Pengeluaran / Transaksi', 'Rp ' . number_format($avgExpense, 0, ',', '.')]);
            fputcsv($handle, ['Pemasukan Terbesar (Single)',   'Rp ' . number_format($maxIncome,  0, ',', '.')]);
            fputcsv($handle, ['Pengeluaran Terbesar (Single)', 'Rp ' . number_format($maxExpense, 0, ',', '.')]);
            fputcsv($handle, []);

            // =========================================================
            // SECTION 4: Breakdown per Kategori
            // =========================================================
            fputcsv($handle, ['=== BREAKDOWN PER KATEGORI ===']);
            fputcsv($handle, ['No.', 'Kategori', 'Tipe', 'Jumlah Transaksi', 'Total (IDR)', 'Total (Format)']);
            foreach ($categoryStats as $i => $cat) {
                fputcsv($handle, [
                    $i + 1,
                    $cat['name'],
                    ucfirst($cat['type']),
                    $cat['count'],
                    (float) $cat['total'],
                    'Rp ' . number_format((float) $cat['total'], 0, ',', '.'),
                ]);
            }
            fputcsv($handle, []);

            // =========================================================
            // SECTION 5: Rincian Semua Transaksi
            // =========================================================
            fputcsv($handle, ['=== RINCIAN TRANSAKSI ===']);
            fputcsv($handle, [
                'No.',
                'Tanggal',
                'Tipe',
                'Kategori',
                'Akun',
                'Jumlah (IDR)',
                'Jumlah (Format)',
                'Deskripsi',
            ]);

            foreach ($transactions as $i => $t) {
                fputcsv($handle, [
                    $i + 1,
                    $t->transaction_date->format('d/m/Y'),
                    ucfirst($t->type),
                    $t->category?->name ?? '-',
                    $t->account?->name ?? '-',
                    (float) $t->amount,
                    'Rp ' . number_format((float) $t->amount, 0, ',', '.'),
                    $t->description ?? '',
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['--- Akhir Laporan ---']);
            fputcsv($handle, ['(c) ' . date('Y') . ' MoneFin Personal Finance Analytics']);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
