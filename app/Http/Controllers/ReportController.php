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
     * Export laporan keuangan ke Excel (.xlsx) profesional — 4 Sheet.
     */
    public function export(Request $request): \Illuminate\Http\Response
    {
        $user = $request->user();
        $currencyCode = $request->query('currency', 'IDR');
        $exchangeRate = (float) $request->query('exchange_rate', 1);
        if ($exchangeRate <= 0) $exchangeRate = 1;
        
        // Currency format untuk Excel number format dan string helper
        $currFormat = match($currencyCode) {
            'USD' => '"$"#,##0.00',
            'EUR' => '"€"#,##0.00',
            'SGD' => '"S$"#,##0.00',
            default => '"Rp "#,##0',
        };
        $fmt = match($currencyCode) {
            'USD' => fn ($v) => '$' . number_format((float) $v, 2, '.', ','),
            'EUR' => fn ($v) => '€' . number_format((float) $v, 2, '.', ','),
            'SGD' => fn ($v) => 'S$' . number_format((float) $v, 2, '.', ','),
            default => fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.'),
        };

        $transactions = Transaction::where('user_id', $user->id)
            ->with(['account', 'category'])
            ->when($request->start_date, fn ($q) => $q->where('transaction_date', '>=', $request->start_date))
            ->when($request->end_date,   fn ($q) => $q->where('transaction_date', '<=', $request->end_date))
            ->when($request->type,        fn ($q) => $q->where('type', $request->type))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->account_id,  fn ($q) => $q->where('account_id', $request->account_id))
            ->orderBy('transaction_date', 'desc')->orderBy('created_at', 'desc')
            ->get();

        // KPIs
        $incTx        = $transactions->where('type', 'income');
        $expTx        = $transactions->where('type', 'expense');
        $totalIncome  = (float) $incTx->sum('amount') / $exchangeRate;
        $totalExpense = (float) $expTx->sum('amount') / $exchangeRate;
        $netCashflow  = $totalIncome - $totalExpense;
        $savingRate   = $totalIncome > 0 ? round($netCashflow / $totalIncome * 100, 1) : 0;
        $burnRate     = $totalIncome > 0 ? round($totalExpense / $totalIncome * 100, 1) : 0;
        $txCount      = $transactions->count();
        $incomeCount  = $incTx->count();
        $expenseCount = $expTx->count();
        $avgIncome    = $incomeCount  > 0 ? $totalIncome  / $incomeCount  : 0;
        $avgExpense   = $expenseCount > 0 ? $totalExpense / $expenseCount : 0;
        $maxExpense   = (float) ($expTx->max('amount') ?? 0) / $exchangeRate;
        $maxIncome    = (float) ($incTx->max('amount') ?? 0) / $exchangeRate;
        $healthScore  = $savingRate >= 30 ? 'A - Sangat Sehat' : ($savingRate >= 20 ? 'B - Sehat' : ($savingRate >= 10 ? 'C - Cukup' : 'D - Perlu Perhatian'));
        $healthColor  = $savingRate >= 30 ? '00685F' : ($savingRate >= 20 ? '059669' : ($savingRate >= 10 ? 'd97706' : 'dc2626'));

        // Monthly trendline
        $driver    = \Illuminate\Support\Facades\DB::getDriverName();
        $monthExpr = $driver === 'sqlite' ? "strftime('%Y-%m', transaction_date)" : "TO_CHAR(transaction_date, 'YYYY-MM')";

        $monthlyRows = Transaction::where('user_id', $user->id)
            ->when($request->start_date, fn ($q) => $q->where('transaction_date', '>=', $request->start_date))
            ->when($request->end_date,   fn ($q) => $q->where('transaction_date', '<=', $request->end_date))
            ->selectRaw("{$monthExpr} AS month, type, SUM(amount) AS total")
            ->groupByRaw("{$monthExpr}, type")->orderByRaw("{$monthExpr} ASC")->get();

        $monthly = [];
        foreach ($monthlyRows as $r) {
            $monthly[$r->month] ??= ['month' => $r->month, 'income' => 0, 'expense' => 0];
            $monthly[$r->month][$r->type] += ((float) $r->total) / $exchangeRate;
        }
        $monthly = collect($monthly)->map(fn ($m) => array_merge($m, [
            'net'       => $m['income'] - $m['expense'],
            'save_rate' => $m['income'] > 0 ? round(($m['income'] - $m['expense']) / $m['income'] * 100, 1) : 0,
            'burn_rate' => $m['income'] > 0 ? round($m['expense'] / $m['income'] * 100, 1) : 0,
        ]))->sortBy('month')->values();

        $surplusMonths = $monthly->where('net', '>=', 0)->count();
        $deficitMonths = $monthly->where('net', '<',  0)->count();
        $bestMonth     = $monthly->sortByDesc('net')->first();

        // Category breakdown
        $grandAll      = (float) $transactions->sum('amount') / $exchangeRate;
        $categoryStats = $transactions
            ->groupBy(fn ($t) => ($t->category?->name ?? 'Lain-lain') . '|||' . $t->type)
            ->map(fn ($group, $key) => [
                'name'  => explode('|||', $key)[0],
                'type'  => explode('|||', $key)[1],
                'count' => $group->count(),
                'total' => (float) $group->sum('amount') / $exchangeRate,
                'avg'   => round(((float) $group->sum('amount') / $group->count()) / $exchangeRate, 2),
            ])->sortByDesc('total')->values();

        $periodLabel = ($request->start_date && $request->end_date)
            ? $request->start_date . ' s/d ' . $request->end_date : 'Semua Periode';

        // Brand palette
        $C_DARK  = '1e293b';
        $C_GREEN = '00685F';
        $C_LGRE  = 'E6F0EF';
        $C_RED   = 'dc2626';
        $C_LRED  = 'FEF2F2';
        $C_AMB   = 'd97706';
        $C_LAMB  = 'FFFBEB';
        $C_SLATE = '475569';
        $C_BGALT = 'F8FAFC';
        $C_WHITE = 'FFFFFF';
        $C_BDR   = 'CBD5E1';



        $applyHdr = function ($ws, $col, $row, $txt, $fgColor, $bgColor, $sz = 9) {
            $cell = $ws->getCell($col . $row);
            $cell->setValue($txt);
            $cell->getStyle()->getFont()->setBold(true)->setSize($sz)->getColor()->setRGB($fgColor);
            $cell->getStyle()->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                 ->getStartColor()->setRGB($bgColor);
            $cell->getStyle()->getAlignment()
                 ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                 ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        };

        $setOutline = function ($ws, $range, $color) {
            $ws->getStyle($range)->getBorders()->getOutline()
               ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)->getColor()->setRGB($color);
        };
        $setAllBorders = function ($ws, $range, $color) {
            $ws->getStyle($range)->getBorders()->getAllBorders()
               ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($color);
        };
        $fillBg = function ($ws, $range, $color) {
            $ws->getStyle($range)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
               ->getStartColor()->setRGB($color);
        };
        $setBanner = function ($ws, $row, $cols, $txt, $fgColor, $bgColor, $h, $sz = 9) {
            $ws->mergeCells("A{$row}:{$cols}{$row}");
            $ws->getCell("A{$row}")->setValue($txt);
            $ws->getRowDimension($row)->setRowHeight($h);
            $ws->getStyle("A{$row}")->getFont()->setSize($sz)->getColor()->setRGB($fgColor);
            $ws->getStyle("A{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($bgColor);
            $ws->getStyle("A{$row}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        };

        // BUILD WORKBOOK
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('MoneFin')->setTitle('Laporan Keuangan Personal')->setSubject($periodLabel);

        // ─── SHEET 1: DASHBOARD KPI ───────────────────────────────────────────
        $ws1 = $spreadsheet->getActiveSheet()->setTitle('Dashboard KPI');
        $ws1->getDefaultRowDimension()->setRowHeight(20);

        $setBanner($ws1, 1, 'I', '   LAPORAN KEUANGAN PERSONAL - MoneFin Financial Analytics v2.0', $C_WHITE, $C_DARK, 40, 16);
        $ws1->getStyle('A1')->getFont()->setBold(true);
        $setBanner($ws1, 2, 'I', '   Dibuat: ' . now()->format('d F Y, H:i') . ' WIB   |   Periode: ' . $periodLabel . '   |   ' . $txCount . ' Transaksi', $C_WHITE, $C_GREEN, 22);
        $ws1->getRowDimension(3)->setRowHeight(8);

        // KPI section title
        $setBanner($ws1, 4, 'I', '   KPI SCORECARD - RINGKASAN EKSEKUTIF', $C_WHITE, $C_GREEN, 28, 11);
        $ws1->getStyle('A4')->getFont()->setBold(true);

        // KPI table header
        $ws1->mergeCells('A5:C5'); $ws1->mergeCells('D5:E5'); $ws1->mergeCells('F5:G5'); $ws1->mergeCells('H5:I5');
        foreach (['A5' => 'INDIKATOR KEUANGAN', 'D5' => 'NILAI', 'F5' => 'STATUS', 'H5' => 'KETERANGAN'] as $c => $v) {
            $ws1->setCellValue($c, $v);
            $ws1->getStyle($c)->getFont()->setBold(true)->setSize(9)->getColor()->setRGB($C_WHITE);
            $ws1->getStyle($c)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($C_SLATE);
            $ws1->getStyle($c)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        }
        $ws1->getRowDimension(5)->setRowHeight(24);

        $kpiRows = [
            ['Total Pemasukan',       $fmt($totalIncome),   'INCOME',                             'Akumulasi seluruh pemasukan dalam periode',    $C_GREEN, $C_LGRE],
            ['Total Pengeluaran',     $fmt($totalExpense),  'EXPENSE',                            'Akumulasi seluruh pengeluaran dalam periode',   $C_RED,   $C_LRED],
            ['Net Cashflow',          $fmt($netCashflow),   $netCashflow >= 0 ? 'SURPLUS' : 'DEFISIT', $netCashflow >= 0 ? 'Keuangan dalam kondisi positif' : 'Pengeluaran melebihi pemasukan', $netCashflow >= 0 ? $C_GREEN : $C_RED, $netCashflow >= 0 ? $C_LGRE : $C_LRED],
            ['Saving Rate',           $savingRate . '%',   $savingRate >= 20 ? 'BAIK' : 'RENDAH','Target saving >= 20% dari total pemasukan',     $savingRate >= 20 ? $C_GREEN : $C_AMB, $savingRate >= 20 ? $C_LGRE : $C_LAMB],
            ['Burn Rate',             $burnRate . '%',     $burnRate <= 80 ? 'TERKENDALI' : 'TINGGI','% pemasukan yang habis dikeluarkan',          $burnRate <= 80 ? $C_GREEN : $C_RED, $burnRate <= 80 ? $C_LGRE : $C_LRED],
            ['Financial Health Score',$healthScore,        'SCORE',                              'Berdasarkan saving rate periode ini',            $healthColor, 'F1F5F9'],
        ];

        $r = 6;
        foreach ($kpiRows as $i => $kpi) {
            $bg = $i % 2 === 0 ? $C_WHITE : 'F8FAFC';
            $ws1->mergeCells("A{$r}:C{$r}"); $ws1->mergeCells("D{$r}:E{$r}"); $ws1->mergeCells("F{$r}:G{$r}"); $ws1->mergeCells("H{$r}:I{$r}");
            $ws1->setCellValue("A{$r}", $kpi[0]); $ws1->setCellValue("D{$r}", $kpi[1]); $ws1->setCellValue("F{$r}", $kpi[2]); $ws1->setCellValue("H{$r}", $kpi[3]);
            $ws1->getStyle("A{$r}")->getFont()->setSize(10)->setBold(true)->getColor()->setRGB($C_DARK);
            $ws1->getStyle("D{$r}")->getFont()->setSize(11)->setBold(true)->getColor()->setRGB($kpi[4]);
            $ws1->getStyle("F{$r}")->getFont()->setSize(9)->setBold(true)->getColor()->setRGB($kpi[4]);
            $ws1->getStyle("H{$r}")->getFont()->setSize(9)->getColor()->setRGB($C_SLATE);
            $fillBg($ws1, "A{$r}:C{$r}", $bg); $fillBg($ws1, "D{$r}:E{$r}", $bg); $fillBg($ws1, "F{$r}:G{$r}", $kpi[5]); $fillBg($ws1, "H{$r}:I{$r}", $bg);
            $ws1->getStyle("A{$r}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setIndent(2);
            $ws1->getStyle("D{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $ws1->getStyle("F{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $ws1->getStyle("H{$r}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setIndent(1);
            $ws1->getRowDimension($r)->setRowHeight(28);
            $r++;
        }
        $setAllBorders($ws1, "A5:I" . ($r - 1), $C_BDR);
        $setOutline($ws1, "A5:I" . ($r - 1), $C_GREEN);

        $ws1->getRowDimension($r)->setRowHeight(8); $r++;

        // Stats section title
        $setBanner($ws1, $r, 'I', '   STATISTIK TRANSAKSI', $C_WHITE, $C_DARK, 28, 11);
        $ws1->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;

        // Stats header
        $ws1->mergeCells("A{$r}:D{$r}"); $ws1->mergeCells("E{$r}:F{$r}"); $ws1->mergeCells("G{$r}:I{$r}");
        foreach (["A{$r}" => 'METRIK', "E{$r}" => 'NILAI', "G{$r}" => 'KETERANGAN'] as $c => $v) {
            $ws1->setCellValue($c, $v);
            $ws1->getStyle($c)->getFont()->setBold(true)->setSize(9)->getColor()->setRGB($C_WHITE);
            $ws1->getStyle($c)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($C_SLATE);
            $ws1->getStyle($c)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        }
        $ws1->getRowDimension($r)->setRowHeight(22);
        $statHdr = $r; $r++;

        $statRows = [
            ['Total Seluruh Transaksi',          $txCount . ' Transaksi',     'Semua income + expense'],
            ['  Transaksi Pemasukan (Income)',    $incomeCount . ' Transaksi', ''],
            ['  Transaksi Pengeluaran (Expense)', $expenseCount . ' Transaksi',''],
            ['Rata-rata per Tx (Income)',         $fmt($avgIncome),             'Nilai rata-rata transaksi income'],
            ['Rata-rata per Tx (Expense)',        $fmt($avgExpense),            'Nilai rata-rata transaksi expense'],
            ['Income Terbesar (Single Tx)',       $fmt($maxIncome),             'Pemasukan tertinggi dalam 1 transaksi'],
            ['Expense Terbesar (Single Tx)',      $fmt($maxExpense),            'Pengeluaran tertinggi dalam 1 transaksi'],
            ['Bulan Surplus / Total',             $surplusMonths . ' / ' . $monthly->count() . ' Bulan', 'Cashflow positif'],
            ['Bulan Terbaik',                     $bestMonth ? ($bestMonth['month'] . ' - Net: ' . $fmt($bestMonth['net'])) : 'N/A', ''],
        ];

        foreach ($statRows as $i => $st) {
            $bg = $i % 2 === 0 ? $C_WHITE : 'F8FAFC';
            $ws1->mergeCells("A{$r}:D{$r}"); $ws1->mergeCells("E{$r}:F{$r}"); $ws1->mergeCells("G{$r}:I{$r}");
            $ws1->setCellValue("A{$r}", $st[0]); $ws1->setCellValue("E{$r}", $st[1]); $ws1->setCellValue("G{$r}", $st[2]);
            $fillBg($ws1, "A{$r}:D{$r}", $bg); $fillBg($ws1, "E{$r}:F{$r}", $bg); $fillBg($ws1, "G{$r}:I{$r}", $bg);
            $ws1->getStyle("A{$r}")->getFont()->setSize(9)->getColor()->setRGB($C_DARK);
            $ws1->getStyle("E{$r}")->getFont()->setSize(9)->setBold(true)->getColor()->setRGB($C_GREEN);
            $ws1->getStyle("G{$r}")->getFont()->setSize(8)->getColor()->setRGB($C_SLATE);
            $ws1->getStyle("A{$r}")->getAlignment()->setIndent(1)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $ws1->getStyle("E{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $ws1->getStyle("G{$r}")->getAlignment()->setIndent(1)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $ws1->getRowDimension($r)->setRowHeight(22);
            $r++;
        }
        $setAllBorders($ws1, "A{$statHdr}:I" . ($r - 1), $C_BDR);
        $setOutline($ws1, "A{$statHdr}:I" . ($r - 1), $C_DARK);

        $ws1->getColumnDimension('A')->setWidth(6);  $ws1->getColumnDimension('B')->setWidth(22);
        $ws1->getColumnDimension('C')->setWidth(14); $ws1->getColumnDimension('D')->setWidth(14);
        $ws1->getColumnDimension('E')->setWidth(20); $ws1->getColumnDimension('F')->setWidth(14);
        $ws1->getColumnDimension('G')->setWidth(16); $ws1->getColumnDimension('H')->setWidth(20);
        $ws1->getColumnDimension('I')->setWidth(18);
        $ws1->freezePane('A3');

        // ─── SHEET 2: TREN BULANAN ────────────────────────────────────────────
        $spreadsheet->createSheet();
        $ws2 = $spreadsheet->setActiveSheetIndex(1)->setTitle('Tren Bulanan');
        $ws2->getDefaultRowDimension()->setRowHeight(20);

        $setBanner($ws2, 1, 'H', '   ANALISIS TREN BULANAN - ' . strtoupper($periodLabel), $C_WHITE, $C_DARK, 38, 14);
        $ws2->getStyle('A1')->getFont()->setBold(true);
        $setBanner($ws2, 2, 'H', '   Surplus: ' . $surplusMonths . ' bulan   |   Defisit: ' . $deficitMonths . ' bulan   |   Total: ' . $monthly->count() . ' bulan   |   Best: ' . ($bestMonth ? $bestMonth['month'] : 'N/A'), $C_WHITE, $C_GREEN, 22);

        $hdrs2 = ['No.', 'Bulan', 'Pemasukan', 'Pengeluaran', 'Net Cashflow', 'Saving Rate', 'Burn Rate', 'Status'];
        foreach ($hdrs2 as $ci => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
            $applyHdr($ws2, $col, 3, $h, $C_WHITE, $C_DARK);
        }
        $ws2->getRowDimension(3)->setRowHeight(24);

        $r2 = 4;
        foreach ($monthly as $mi => $m) {
            $isSurplus = $m['net'] >= 0;
            $isBest    = $bestMonth && $m['month'] === $bestMonth['month'];
            $bg        = $isBest ? 'FFFBEB' : ($mi % 2 === 0 ? $C_WHITE : $C_BGALT);

            $ws2->setCellValue("A{$r2}", $mi + 1);  $ws2->setCellValue("B{$r2}", $m['month']);
            $ws2->setCellValue("C{$r2}", $m['income']); $ws2->setCellValue("D{$r2}", $m['expense']);
            $ws2->setCellValue("E{$r2}", $m['net']); $ws2->setCellValue("F{$r2}", $m['save_rate'] / 100);
            $ws2->setCellValue("G{$r2}", $m['burn_rate'] / 100);
            $ws2->setCellValue("H{$r2}", $isSurplus ? ($isBest ? 'Surplus BEST' : 'Surplus') : 'Defisit');

            $ws2->getStyle("C{$r2}")->getNumberFormat()->setFormatCode($currFormat);
            $ws2->getStyle("D{$r2}")->getNumberFormat()->setFormatCode($currFormat);
            $ws2->getStyle("E{$r2}")->getNumberFormat()->setFormatCode($currFormat);
            $ws2->getStyle("F{$r2}")->getNumberFormat()->setFormatCode('0.0%');
            $ws2->getStyle("G{$r2}")->getNumberFormat()->setFormatCode('0.0%');

            $fillBg($ws2, "A{$r2}:H{$r2}", $bg);
            $ws2->getStyle("C{$r2}")->getFont()->getColor()->setRGB($C_GREEN);
            $ws2->getStyle("D{$r2}")->getFont()->getColor()->setRGB($C_RED);
            $ws2->getStyle("E{$r2}")->getFont()->setBold(true)->getColor()->setRGB($isSurplus ? $C_GREEN : $C_RED);
            $ws2->getStyle("H{$r2}")->getFont()->setBold(true)->getColor()->setRGB($isSurplus ? $C_GREEN : $C_RED);
            $ws2->getStyle("A{$r2}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $ws2->getStyle("C{$r2}:G{$r2}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $ws2->getStyle("H{$r2}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $ws2->getRowDimension($r2)->setRowHeight(22);
            $r2++;
        }

        // Total row
        $ws2->setCellValue("B{$r2}", 'TOTAL PERIODE');
        $ws2->setCellValue("C{$r2}", $totalIncome); $ws2->setCellValue("D{$r2}", $totalExpense);
        $ws2->setCellValue("E{$r2}", $netCashflow); $ws2->setCellValue("F{$r2}", $savingRate / 100);
        $ws2->setCellValue("G{$r2}", $burnRate / 100); $ws2->setCellValue("H{$r2}", $netCashflow >= 0 ? 'SURPLUS' : 'DEFISIT');
        $ws2->getStyle("C{$r2}")->getNumberFormat()->setFormatCode($currFormat);
        $ws2->getStyle("D{$r2}")->getNumberFormat()->setFormatCode($currFormat);
        $ws2->getStyle("E{$r2}")->getNumberFormat()->setFormatCode($currFormat);
        $ws2->getStyle("F{$r2}")->getNumberFormat()->setFormatCode('0.0%');
        $ws2->getStyle("G{$r2}")->getNumberFormat()->setFormatCode('0.0%');
        $fillBg($ws2, "A{$r2}:H{$r2}", 'EFF6FF');
        $ws2->getStyle("A{$r2}:H{$r2}")->getFont()->setBold(true);
        $ws2->getStyle("E{$r2}")->getFont()->getColor()->setRGB($netCashflow >= 0 ? $C_GREEN : $C_RED);
        $ws2->getStyle("H{$r2}")->getFont()->getColor()->setRGB($netCashflow >= 0 ? $C_GREEN : $C_RED);
        $ws2->getStyle("B{$r2}:H{$r2}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $ws2->getStyle("C{$r2}:G{$r2}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $ws2->getRowDimension($r2)->setRowHeight(24);

        $setAllBorders($ws2, "A3:H{$r2}", $C_BDR);
        $setOutline($ws2, "A3:H{$r2}", $C_DARK);

        $ws2->getColumnDimension('A')->setWidth(6);  $ws2->getColumnDimension('B')->setWidth(14);
        $ws2->getColumnDimension('C')->setWidth(22); $ws2->getColumnDimension('D')->setWidth(22);
        $ws2->getColumnDimension('E')->setWidth(22); $ws2->getColumnDimension('F')->setWidth(14);
        $ws2->getColumnDimension('G')->setWidth(14); $ws2->getColumnDimension('H')->setWidth(16);
        $ws2->freezePane('A4');

        // ─── SHEET 3: KATEGORI ────────────────────────────────────────────────
        $spreadsheet->createSheet();
        $ws3 = $spreadsheet->setActiveSheetIndex(2)->setTitle('Breakdown Kategori');
        $ws3->getDefaultRowDimension()->setRowHeight(20);

        $setBanner($ws3, 1, 'H', '   BREAKDOWN KATEGORI - RANKED BY TOTAL', $C_WHITE, $C_DARK, 38, 14);
        $ws3->getStyle('A1')->getFont()->setBold(true);
        $setBanner($ws3, 2, 'H', '   ' . $categoryStats->count() . ' Kategori Ditemukan   |   Grand Total: ' . $fmt($grandAll), $C_WHITE, $C_GREEN, 22);

        $hdrs3 = ['Rank', 'Kategori', 'Tipe', 'Jml Tx', 'Total (IDR)', 'Avg / Tx', '% dr Total', 'Label'];
        foreach ($hdrs3 as $ci => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
            $applyHdr($ws3, $col, 3, $h, $C_WHITE, $C_DARK);
        }
        $ws3->getRowDimension(3)->setRowHeight(24);

        $r3 = 4;
        foreach ($categoryStats as $ci => $cat) {
            $isInc = $cat['type'] === 'income';
            $pct   = $grandAll > 0 ? round($cat['total'] / $grandAll * 100, 1) : 0;
            $bg    = $ci % 2 === 0 ? $C_WHITE : $C_BGALT;
            $tc    = $isInc ? $C_GREEN : $C_RED;
            $label = $ci === 0 ? 'Terbesar' : ($ci === $categoryStats->count() - 1 ? 'Terkecil' : '');

            $ws3->setCellValue("A{$r3}", $ci + 1);     $ws3->setCellValue("B{$r3}", $cat['name']);
            $ws3->setCellValue("C{$r3}", ucfirst($cat['type'])); $ws3->setCellValue("D{$r3}", $cat['count']);
            $ws3->setCellValue("E{$r3}", $cat['total']); $ws3->setCellValue("F{$r3}", $cat['avg']);
            $ws3->setCellValue("G{$r3}", $pct / 100);  $ws3->setCellValue("H{$r3}", $label);

            $ws3->getStyle("E{$r3}")->getNumberFormat()->setFormatCode($currFormat);
            $ws3->getStyle("F{$r3}")->getNumberFormat()->setFormatCode($currFormat);
            $ws3->getStyle("G{$r3}")->getNumberFormat()->setFormatCode('0.0%');

            $fillBg($ws3, "A{$r3}:H{$r3}", $bg);
            $ws3->getStyle("A{$r3}")->getFont()->setBold(true)->getColor()->setRGB($C_SLATE);
            $ws3->getStyle("B{$r3}")->getFont()->setBold(true)->getColor()->setRGB($C_DARK);
            $ws3->getStyle("C{$r3}")->getFont()->setBold(true)->getColor()->setRGB($tc);
            $ws3->getStyle("E{$r3}")->getFont()->setBold(true)->getColor()->setRGB($tc);
            $ws3->getStyle("G{$r3}")->getFont()->setBold(true);
            $ws3->getStyle("H{$r3}")->getFont()->setBold(true)->getColor()->setRGB($ci === 0 ? $C_AMB : $C_SLATE);
            $ws3->getStyle("A{$r3}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $ws3->getStyle("C{$r3}:D{$r3}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $ws3->getStyle("E{$r3}:G{$r3}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $ws3->getStyle("H{$r3}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $ws3->getRowDimension($r3)->setRowHeight(22);
            $r3++;
        }
        $setAllBorders($ws3, "A3:H{$r3}", $C_BDR);
        $setOutline($ws3, "A3:H{$r3}", $C_DARK);

        $ws3->getColumnDimension('A')->setWidth(7);  $ws3->getColumnDimension('B')->setWidth(26);
        $ws3->getColumnDimension('C')->setWidth(14); $ws3->getColumnDimension('D')->setWidth(10);
        $ws3->getColumnDimension('E')->setWidth(22); $ws3->getColumnDimension('F')->setWidth(20);
        $ws3->getColumnDimension('G')->setWidth(13); $ws3->getColumnDimension('H')->setWidth(12);
        $ws3->freezePane('A4');

        // ─── SHEET 4: DETAIL TRANSAKSI ────────────────────────────────────────
        $spreadsheet->createSheet();
        $ws4 = $spreadsheet->setActiveSheetIndex(3)->setTitle('Detail Transaksi');
        $ws4->getDefaultRowDimension()->setRowHeight(20);

        $setBanner($ws4, 1, 'H', '   RINCIAN LENGKAP TRANSAKSI - ' . $txCount . ' TRANSAKSI', $C_WHITE, $C_DARK, 38, 14);
        $ws4->getStyle('A1')->getFont()->setBold(true);
        $setBanner($ws4, 2, 'H', '   Pemasukan: ' . $fmt($totalIncome) . '   |   Pengeluaran: ' . $fmt($totalExpense) . '   |   Net: ' . $fmt($netCashflow), $C_WHITE, $netCashflow >= 0 ? $C_GREEN : $C_RED, 22);

        $hdrs4 = ['No.', 'Tanggal', 'Tipe', 'Kategori', 'Akun', 'Jumlah', 'Bulan', 'Deskripsi'];
        foreach ($hdrs4 as $ci => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
            $applyHdr($ws4, $col, 3, $h, $C_WHITE, $C_DARK);
        }
        $ws4->getRowDimension(3)->setRowHeight(24);

        $r4 = 4;
        foreach ($transactions as $ti => $t) {
            $isInc = $t->type === 'income';
            $bg    = $ti % 2 === 0 ? $C_WHITE : $C_BGALT;
            $tc    = $isInc ? $C_GREEN : $C_RED;

            $ws4->setCellValue("A{$r4}", $ti + 1);
            $ws4->setCellValue("B{$r4}", $t->transaction_date->format('d/m/Y'));
            $ws4->setCellValue("C{$r4}", ucfirst($t->type));
            $ws4->setCellValue("D{$r4}", $t->category?->name ?? '-');
            $ws4->setCellValue("E{$r4}", $t->account?->name  ?? '-');
            $ws4->setCellValue("F{$r4}", (float) $t->amount / $exchangeRate);
            $ws4->setCellValue("G{$r4}", $t->transaction_date->format('Y-m'));
            $ws4->setCellValue("H{$r4}", $t->description ?? '');

            $ws4->getStyle("F{$r4}")->getNumberFormat()->setFormatCode($currFormat);

            $fillBg($ws4, "A{$r4}:H{$r4}", $bg);
            $ws4->getStyle("C{$r4}")->getFont()->setBold(true)->getColor()->setRGB($tc);
            $ws4->getStyle("F{$r4}")->getFont()->setBold(true)->getColor()->setRGB($tc);
            $ws4->getStyle("A{$r4}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $ws4->getStyle("B{$r4}:C{$r4}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $ws4->getStyle("F{$r4}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $ws4->getStyle("G{$r4}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $ws4->getRowDimension($r4)->setRowHeight(22);
            $r4++;
        }
        $setAllBorders($ws4, "A3:H{$r4}", $C_BDR);
        $setOutline($ws4, "A3:H{$r4}", $C_DARK);
        $ws4->setAutoFilter("A3:H3");

        $ws4->getColumnDimension('A')->setWidth(7);  $ws4->getColumnDimension('B')->setWidth(14);
        $ws4->getColumnDimension('C')->setWidth(14); $ws4->getColumnDimension('D')->setWidth(24);
        $ws4->getColumnDimension('E')->setWidth(18); $ws4->getColumnDimension('F')->setWidth(22);
        $ws4->getColumnDimension('G')->setWidth(12); $ws4->getColumnDimension('H')->setWidth(36);
        $ws4->freezePane('A4');

        // Activate dashboard
        $spreadsheet->setActiveSheetIndex(0);

        // Stream xlsx
        $filename = 'MoneFin_LaporanKeuangan_' . now()->format('Ymd_His') . '.xlsx';
        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
            'Pragma'              => 'public',
        ]);
    }
}