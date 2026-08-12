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
     * Export laporan keuangan ke CSV profesional bergaya data analyst / akuntan.
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

        $incTx        = $transactions->where('type', 'income');
        $expTx        = $transactions->where('type', 'expense');
        $totalIncome  = (float) $incTx->sum('amount');
        $totalExpense = (float) $expTx->sum('amount');
        $netCashflow  = $totalIncome - $totalExpense;
        $savingRate   = $totalIncome > 0 ? round($netCashflow / $totalIncome * 100, 1) : 0;
        $burnRate     = $totalIncome > 0 ? round($totalExpense / $totalIncome * 100, 1) : 0;
        $txCount      = $transactions->count();
        $incomeCount  = $incTx->count();
        $expenseCount = $expTx->count();
        $avgIncome    = $incomeCount  > 0 ? $totalIncome  / $incomeCount  : 0;
        $avgExpense   = $expenseCount > 0 ? $totalExpense / $expenseCount : 0;
        $maxExpense   = (float) ($expTx->max('amount') ?? 0);
        $maxIncome    = (float) ($incTx->max('amount') ?? 0);
        $minExpense   = (float) ($expTx->min('amount') ?? 0);
        $healthScore  = $savingRate >= 30 ? 'A (Sangat Sehat)' : ($savingRate >= 20 ? 'B (Sehat)' : ($savingRate >= 10 ? 'C (Cukup)' : 'D (Perlu Perhatian)'));

        $driver = \Illuminate\Support\Facades\DB::getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', transaction_date)"
            : "TO_CHAR(transaction_date, 'YYYY-MM')";

        $monthlyRows = Transaction::where('user_id', $user->id)
            ->when($request->start_date, fn ($q) => $q->where('transaction_date', '>=', $request->start_date))
            ->when($request->end_date,   fn ($q) => $q->where('transaction_date', '<=', $request->end_date))
            ->selectRaw("{$monthExpr} AS month, type, SUM(amount) AS total")
            ->groupByRaw("{$monthExpr}, type")
            ->orderByRaw("{$monthExpr} ASC")
            ->get();

        $monthly = [];
        foreach ($monthlyRows as $r) {
            $monthly[$r->month] ??= ['month' => $r->month, 'income' => 0, 'expense' => 0];
            $monthly[$r->month][$r->type] += (float) $r->total;
        }
        $monthly = collect($monthly)->map(fn ($m) => array_merge($m, [
            'net'       => $m['income'] - $m['expense'],
            'save_rate' => $m['income'] > 0 ? round(($m['income'] - $m['expense']) / $m['income'] * 100, 1) : 0,
            'burn_rate' => $m['income'] > 0 ? round($m['expense'] / $m['income'] * 100, 1) : 0,
        ]))->sortBy('month')->values();

        $surplusMonths = $monthly->where('net', '>=', 0)->count();
        $deficitMonths = $monthly->where('net', '<',  0)->count();
        $bestMonth     = $monthly->sortByDesc('net')->first();
        $worstMonth    = $monthly->sortBy('net')->first();

        $grandAll      = (float) $transactions->sum('amount');
        $categoryStats = $transactions
            ->groupBy(fn ($t) => ($t->category?->name ?? 'Lain-lain') . '|' . $t->type)
            ->map(fn ($group, $key) => [
                'name'  => explode('|', $key)[0],
                'type'  => explode('|', $key)[1],
                'count' => $group->count(),
                'total' => (float) $group->sum('amount'),
                'avg'   => round((float) $group->sum('amount') / $group->count(), 0),
            ])
            ->sortByDesc('total')
            ->values();

        $top5Expenses = $expTx->sortByDesc('amount')->take(5)->values();

        $periodLabel = ($request->start_date && $request->end_date)
            ? "{$request->start_date} s/d {$request->end_date}"
            : 'Semua Periode';

        $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

        $filename = 'MoneFin_LaporanKeuangan_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use (
            $transactions, $categoryStats, $monthly, $top5Expenses,
            $totalIncome, $totalExpense, $netCashflow, $savingRate, $burnRate,
            $txCount, $incomeCount, $expenseCount, $avgIncome, $avgExpense,
            $maxIncome, $maxExpense, $minExpense, $healthScore,
            $surplusMonths, $deficitMonths, $bestMonth, $worstMonth,
            $grandAll, $periodLabel, $rp
        ) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            $sep = fn () => fputcsv($handle, []);

            // COVER
            fputcsv($handle, ['LAPORAN KEUANGAN PERSONAL', 'MoneFin Financial Analytics v2.0']);
            fputcsv($handle, ['Dibuat Pada', now()->format('d F Y, H:i') . ' WIB']);
            fputcsv($handle, ['Periode', $periodLabel]);
            fputcsv($handle, ['Catatan', 'Dokumen rahasia. Hanya untuk penggunaan pribadi.']);
            $sep();

            // SECTION 1: KPI SCORECARD
            fputcsv($handle, ['=== SECTION 1: RINGKASAN EKSEKUTIF (KPI SCORECARD) ===']);
            $sep();
            fputcsv($handle, ['INDIKATOR', 'NILAI (FORMAT)', 'NILAI (IDR)', 'KETERANGAN']);
            fputcsv($handle, ['Total Pemasukan',  $rp($totalIncome),  $totalIncome,  'Akumulasi seluruh income dalam periode']);
            fputcsv($handle, ['Total Pengeluaran', $rp($totalExpense), $totalExpense, 'Akumulasi seluruh expense dalam periode']);
            fputcsv($handle, ['Net Cashflow',      $rp($netCashflow),  $netCashflow,  $netCashflow >= 0 ? 'SURPLUS - Keuangan Sehat' : 'DEFISIT - Perlu Evaluasi']);
            fputcsv($handle, ['Saving Rate',        $savingRate . '%',  '',            $savingRate >= 20 ? 'BAIK - Target >= 20% tercapai' : 'RENDAH - Target 20% belum tercapai']);
            fputcsv($handle, ['Burn Rate',          $burnRate . '%',    '',            $burnRate <= 80 ? 'Terkendali' : 'TINGGI - Efisiensi anggaran perlu ditingkatkan']);
            fputcsv($handle, ['Financial Score',    $healthScore,       '',            'Berdasarkan saving rate periode ini']);
            $sep();

            // SECTION 2: STATISTIK TRANSAKSI
            fputcsv($handle, ['=== SECTION 2: STATISTIK TRANSAKSI ===']);
            $sep();
            fputcsv($handle, ['METRIK', 'NILAI', 'UNIT']);
            fputcsv($handle, ['Total Transaksi',                  $txCount,         'transaksi']);
            fputcsv($handle, ['Transaksi Pemasukan',              $incomeCount,     'transaksi']);
            fputcsv($handle, ['Transaksi Pengeluaran',            $expenseCount,    'transaksi']);
            fputcsv($handle, ['Rata-rata per Tx Pemasukan',       $rp($avgIncome),  '']);
            fputcsv($handle, ['Rata-rata per Tx Pengeluaran',     $rp($avgExpense), '']);
            fputcsv($handle, ['Pemasukan Terbesar (Single Tx)',   $rp($maxIncome),  '']);
            fputcsv($handle, ['Pengeluaran Terbesar (Single Tx)', $rp($maxExpense), '']);
            fputcsv($handle, ['Pengeluaran Terkecil (Single Tx)', $rp($minExpense), '']);
            $sep();

            // SECTION 3: TREN BULANAN
            fputcsv($handle, ['=== SECTION 3: ANALISIS TREN BULANAN ===']);
            fputcsv($handle, ['Bulan Surplus: ' . $surplusMonths . '   |   Bulan Defisit: ' . $deficitMonths]);
            if ($bestMonth)  fputcsv($handle, ['Bulan Terbaik:  ' . ($bestMonth['month'] ?? '-') . ' | Net: ' . $rp($bestMonth['net'] ?? 0) . ' | Save Rate: ' . ($bestMonth['save_rate'] ?? 0) . '%']);
            if ($worstMonth) fputcsv($handle, ['Bulan Terburuk: ' . ($worstMonth['month'] ?? '-') . ' | Net: ' . $rp($worstMonth['net'] ?? 0) . ' | Burn Rate: ' . ($worstMonth['burn_rate'] ?? 0) . '%']);
            $sep();
            fputcsv($handle, ['Bulan', 'Pemasukan', 'Pengeluaran', 'Net Cashflow', 'Saving Rate', 'Burn Rate', 'Status']);
            foreach ($monthly as $m) {
                fputcsv($handle, [
                    $m['month'], $m['income'], $m['expense'], $m['net'],
                    $m['save_rate'] . '%', $m['burn_rate'] . '%',
                    $m['net'] >= 0 ? 'Surplus' : 'Defisit',
                ]);
            }
            $sep();

            // SECTION 4: BREAKDOWN KATEGORI
            fputcsv($handle, ['=== SECTION 4: BREAKDOWN KATEGORI (RANKED) ===']);
            $sep();
            fputcsv($handle, ['Rank', 'Kategori', 'Tipe', 'Jml Tx', 'Total (IDR)', 'Total (Format)', 'Avg per Tx', '% dari Total']);
            foreach ($categoryStats as $i => $cat) {
                $pct = $grandAll > 0 ? round($cat['total'] / $grandAll * 100, 1) : 0;
                fputcsv($handle, [
                    $i + 1, $cat['name'], ucfirst($cat['type']), $cat['count'],
                    $cat['total'], $rp($cat['total']), $rp($cat['avg']), $pct . '%',
                ]);
            }
            $sep();

            // SECTION 5: TOP 5 PENGELUARAN
            fputcsv($handle, ['=== SECTION 5: TOP 5 PENGELUARAN TERBESAR ===']);
            $sep();
            fputcsv($handle, ['Rank', 'Tanggal', 'Kategori', 'Akun', 'Jumlah', 'Deskripsi']);
            foreach ($top5Expenses as $rank => $t) {
                fputcsv($handle, [
                    $rank + 1,
                    $t->transaction_date->format('d/m/Y'),
                    $t->category?->name ?? '-',
                    $t->account?->name  ?? '-',
                    $rp($t->amount),
                    $t->description ?? '',
                ]);
            }
            $sep();

            // SECTION 6: RINCIAN TRANSAKSI
            fputcsv($handle, ['=== SECTION 6: RINCIAN LENGKAP TRANSAKSI ===']);
            $sep();
            fputcsv($handle, ['No.', 'Tanggal', 'Tipe', 'Kategori', 'Akun', 'Jumlah (IDR)', 'Jumlah (Format)', 'Deskripsi']);
            foreach ($transactions as $i => $t) {
                fputcsv($handle, [
                    $i + 1,
                    $t->transaction_date->format('d/m/Y'),
                    ucfirst($t->type),
                    $t->category?->name ?? '-',
                    $t->account?->name  ?? '-',
                    (float) $t->amount,
                    $rp($t->amount),
                    $t->description ?? '',
                ]);
            }
            $sep();
            fputcsv($handle, ['Generated by MoneFin Financial Analytics System v2.0']);
            fputcsv($handle, ['(c) ' . date('Y') . ' MoneFin. Seluruh data bersifat rahasia.']);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}