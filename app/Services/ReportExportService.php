<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExportService
{
    // Brand palette colors
    private const C_DARK  = '1e293b';
    private const C_GREEN = '00685F';
    private const C_LGRE  = 'E6F0EF';
    private const C_RED   = 'dc2626';
    private const C_LRED  = 'FEF2F2';
    private const C_AMB   = 'd97706';
    private const C_LAMB  = 'FFFBEB';
    private const C_SLATE = '475569';
    private const C_BGALT = 'F8FAFC';
    private const C_WHITE = 'FFFFFF';
    private const C_BDR   = 'CBD5E1';

    /**
     * Export laporan keuangan ke Excel (.xlsx) profesional 4 Sheet:
     * Sheet 1: Dashboard KPI
     * Sheet 2: Tren Bulanan
     * Sheet 3: Breakdown Kategori
     * Sheet 4: Detail Transaksi
     */
    public function exportToExcel(User $user, array $params = []): Response
    {
        $currencyCode = $params['currency'] ?? 'IDR';
        $exchangeRate = (float) ($params['exchange_rate'] ?? 1);
        if ($exchangeRate <= 0) {
            $exchangeRate = 1;
        }

        $currFormat = $this->getCurrencyFormat($currencyCode);
        $fmt = $this->getCurrencyFormatter($currencyCode);

        // Fetch transactions according to filters
        $startDate  = $params['start_date'] ?? null;
        $endDate    = $params['end_date'] ?? null;
        $type       = $params['type'] ?? null;
        $categoryId = $params['category_id'] ?? null;
        $accountId  = $params['account_id'] ?? null;

        $transactions = Transaction::where('user_id', $user->id)
            ->with(['account', 'category'])
            ->when($startDate, fn ($q) => $q->where('transaction_date', '>=', $startDate))
            ->when($endDate,   fn ($q) => $q->where('transaction_date', '<=', $endDate))
            ->when($type,        fn ($q) => $q->where('type', $type))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($accountId,  fn ($q) => $q->where('account_id', $accountId))
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $kpis = $this->calculateKpis($transactions, $exchangeRate);
        $periodLabel = ($startDate && $endDate)
            ? $startDate . ' s/d ' . $endDate
            : 'Semua Periode';

        $monthlyData = $this->calculateMonthlyTrends($user->id, $startDate, $endDate, $exchangeRate);
        $categoryStats = $this->calculateCategoryBreakdown($transactions, $exchangeRate);

        // Inisialisasi Workbook
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('MoneFin')
            ->setTitle('Laporan Keuangan Personal')
            ->setSubject($periodLabel);

        // Build 4 Sheets
        $this->buildDashboardSheet($spreadsheet, $kpis, $periodLabel, $fmt, $monthlyData);
        $this->buildMonthlyTrendsSheet($spreadsheet, $monthlyData, $periodLabel, $currFormat, $kpis);
        $this->buildCategoryBreakdownSheet($spreadsheet, $categoryStats, $fmt, $currFormat);
        $this->buildTransactionDetailSheet($spreadsheet, $transactions, $kpis, $fmt, $currFormat, $exchangeRate);

        // Aktifkan sheet pertama (Dashboard)
        $spreadsheet->setActiveSheetIndex(0);

        // Stream output
        $filename = 'MoneFin_LaporanKeuangan_' . now()->format('Ymd_His') . '.xlsx';
        $writer   = new Xlsx($spreadsheet);

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

    private function getCurrencyFormat(string $currencyCode): string
    {
        return match ($currencyCode) {
            'USD'   => '"$"#,##0.00',
            'EUR'   => '"€"#,##0.00',
            'SGD'   => '"S$"#,##0.00',
            default => '"Rp "#,##0',
        };
    }

    private function getCurrencyFormatter(string $currencyCode): \Closure
    {
        return match ($currencyCode) {
            'USD'   => fn ($v) => '$' . number_format((float) $v, 2, '.', ','),
            'EUR'   => fn ($v) => '€' . number_format((float) $v, 2, '.', ','),
            'SGD'   => fn ($v) => 'S$' . number_format((float) $v, 2, '.', ','),
            default => fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.'),
        };
    }

    private function calculateKpis(Collection $transactions, float $exchangeRate): array
    {
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
        $avgIncome    = $incomeCount > 0 ? $totalIncome / $incomeCount : 0;
        $avgExpense   = $expenseCount > 0 ? $totalExpense / $expenseCount : 0;
        $maxExpense   = (float) ($expTx->max('amount') ?? 0) / $exchangeRate;
        $maxIncome    = (float) ($incTx->max('amount') ?? 0) / $exchangeRate;
        $healthScore  = $savingRate >= 30 ? 'A - Sangat Sehat' : ($savingRate >= 20 ? 'B - Sehat' : ($savingRate >= 10 ? 'C - Cukup' : 'D - Perlu Perhatian'));
        $healthColor  = $savingRate >= 30 ? '00685F' : ($savingRate >= 20 ? '059669' : ($savingRate >= 10 ? 'd97706' : 'dc2626'));

        return compact(
            'totalIncome', 'totalExpense', 'netCashflow', 'savingRate', 'burnRate',
            'txCount', 'incomeCount', 'expenseCount', 'avgIncome', 'avgExpense',
            'maxExpense', 'maxIncome', 'healthScore', 'healthColor'
        );
    }

    private function calculateMonthlyTrends(int $userId, ?string $startDate, ?string $endDate, float $exchangeRate): \Illuminate\Support\Collection
    {
        $driver    = DB::getDriverName();
        $monthExpr = $driver === 'sqlite' ? "strftime('%Y-%m', transaction_date)" : "TO_CHAR(transaction_date, 'YYYY-MM')";

        $monthlyRows = Transaction::where('user_id', $userId)
            ->when($startDate, fn ($q) => $q->where('transaction_date', '>=', $startDate))
            ->when($endDate,   fn ($q) => $q->where('transaction_date', '<=', $endDate))
            ->selectRaw("{$monthExpr} AS month, type, SUM(amount) AS total")
            ->groupByRaw("{$monthExpr}, type")
            ->orderByRaw("{$monthExpr} ASC")
            ->get();

        $monthly = [];
        foreach ($monthlyRows as $r) {
            $monthly[$r->month] ??= ['month' => $r->month, 'income' => 0, 'expense' => 0];
            $monthly[$r->month][$r->type] += ((float) $r->total) / $exchangeRate;
        }

        return collect($monthly)->map(fn ($m) => array_merge($m, [
            'net'       => $m['income'] - $m['expense'],
            'save_rate' => $m['income'] > 0 ? round(($m['income'] - $m['expense']) / $m['income'] * 100, 1) : 0,
            'burn_rate' => $m['income'] > 0 ? round($m['expense'] / $m['income'] * 100, 1) : 0,
        ]))->sortBy('month')->values();
    }

    private function calculateCategoryBreakdown(Collection $transactions, float $exchangeRate): \Illuminate\Support\Collection
    {
        return $transactions
            ->groupBy(fn ($t) => ($t->category?->name ?? 'Lain-lain') . '|||' . $t->type)
            ->map(fn ($group, $key) => [
                'name'  => explode('|||', $key)[0],
                'type'  => explode('|||', $key)[1],
                'count' => $group->count(),
                'total' => (float) $group->sum('amount') / $exchangeRate,
                'avg'   => round(((float) $group->sum('amount') / $group->count()) / $exchangeRate, 2),
            ])
            ->sortByDesc('total')
            ->values();
    }

    // ─── SHEET 1: DASHBOARD KPI ───────────────────────────────────────────

    private function buildDashboardSheet(Spreadsheet $spreadsheet, array $kpis, string $periodLabel, \Closure $fmt, \Illuminate\Support\Collection $monthly): void
    {
        $ws = $spreadsheet->getActiveSheet()->setTitle('Dashboard KPI');
        $ws->getDefaultRowDimension()->setRowHeight(20);

        $this->setBanner($ws, 1, 'I', '   LAPORAN KEUANGAN PERSONAL - MoneFin Financial Analytics v2.0', self::C_WHITE, self::C_DARK, 40, 16);
        $ws->getStyle('A1')->getFont()->setBold(true);
        $this->setBanner($ws, 2, 'I', '   Dibuat: ' . now()->format('d F Y, H:i') . ' WIB   |   Periode: ' . $periodLabel . '   |   ' . $kpis['txCount'] . ' Transaksi', self::C_WHITE, self::C_GREEN, 22);
        $ws->getRowDimension(3)->setRowHeight(8);

        // KPI section title
        $this->setBanner($ws, 4, 'I', '   KPI SCORECARD - RINGKASAN EKSEKUTIF', self::C_WHITE, self::C_GREEN, 28, 11);
        $ws->getStyle('A4')->getFont()->setBold(true);

        // KPI table header
        $ws->mergeCells('A5:C5'); $ws->mergeCells('D5:E5'); $ws->mergeCells('F5:G5'); $ws->mergeCells('H5:I5');
        foreach (['A5' => 'INDIKATOR KEUANGAN', 'D5' => 'NILAI', 'F5' => 'STATUS', 'H5' => 'KETERANGAN'] as $c => $v) {
            $ws->setCellValue($c, $v);
            $ws->getStyle($c)->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(self::C_WHITE);
            $ws->getStyle($c)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::C_SLATE);
            $ws->getStyle($c)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        }
        $ws->getRowDimension(5)->setRowHeight(24);

        $kpiRows = [
            ['Total Pemasukan',       $fmt($kpis['totalIncome']),   'INCOME',                             'Akumulasi seluruh pemasukan dalam periode',    self::C_GREEN, self::C_LGRE],
            ['Total Pengeluaran',     $fmt($kpis['totalExpense']),  'EXPENSE',                            'Akumulasi seluruh pengeluaran dalam periode',   self::C_RED,   self::C_LRED],
            ['Net Cashflow',          $fmt($kpis['netCashflow']),   $kpis['netCashflow'] >= 0 ? 'SURPLUS' : 'DEFISIT', $kpis['netCashflow'] >= 0 ? 'Keuangan dalam kondisi positif' : 'Pengeluaran melebihi pemasukan', $kpis['netCashflow'] >= 0 ? self::C_GREEN : self::C_RED, $kpis['netCashflow'] >= 0 ? self::C_LGRE : self::C_LRED],
            ['Saving Rate',           $kpis['savingRate'] . '%',    $kpis['savingRate'] >= 20 ? 'BAIK' : 'RENDAH','Target saving >= 20% dari total pemasukan',     $kpis['savingRate'] >= 20 ? self::C_GREEN : self::C_AMB, $kpis['savingRate'] >= 20 ? self::C_LGRE : self::C_LAMB],
            ['Burn Rate',             $kpis['burnRate'] . '%',      $kpis['burnRate'] <= 80 ? 'TERKENDALI' : 'TINGGI','% pemasukan yang habis dikeluarkan',          $kpis['burnRate'] <= 80 ? self::C_GREEN : self::C_RED, $kpis['burnRate'] <= 80 ? self::C_LGRE : self::C_LRED],
            ['Financial Health Score',$kpis['healthScore'],         'SCORE',                              'Berdasarkan saving rate periode ini',            $kpis['healthColor'], 'F1F5F9'],
        ];

        $r = 6;
        foreach ($kpiRows as $i => $kpi) {
            $bg = $i % 2 === 0 ? self::C_WHITE : 'F8FAFC';
            $ws->mergeCells("A{$r}:C{$r}"); $ws->mergeCells("D{$r}:E{$r}"); $ws->mergeCells("F{$r}:G{$r}"); $ws->mergeCells("H{$r}:I{$r}");
            $ws->setCellValue("A{$r}", $kpi[0]); $ws->setCellValue("D{$r}", $kpi[1]); $ws->setCellValue("F{$r}", $kpi[2]); $ws->setCellValue("H{$r}", $kpi[3]);
            $ws->getStyle("A{$r}")->getFont()->setSize(10)->setBold(true)->getColor()->setRGB(self::C_DARK);
            $ws->getStyle("D{$r}")->getFont()->setSize(11)->setBold(true)->getColor()->setRGB($kpi[4]);
            $ws->getStyle("F{$r}")->getFont()->setSize(9)->setBold(true)->getColor()->setRGB($kpi[4]);
            $ws->getStyle("H{$r}")->getFont()->setSize(9)->getColor()->setRGB(self::C_SLATE);
            $this->fillBg($ws, "A{$r}:C{$r}", $bg); $this->fillBg($ws, "D{$r}:E{$r}", $bg); $this->fillBg($ws, "F{$r}:G{$r}", $kpi[5]); $this->fillBg($ws, "H{$r}:I{$r}", $bg);
            $ws->getStyle("A{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(2);
            $ws->getStyle("D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $ws->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $ws->getStyle("H{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
            $ws->getRowDimension($r)->setRowHeight(28);
            $r++;
        }
        $this->setAllBorders($ws, "A5:I" . ($r - 1), self::C_BDR);
        $this->setOutline($ws, "A5:I" . ($r - 1), self::C_GREEN);

        $ws->getRowDimension($r)->setRowHeight(8); $r++;

        // Stats section title
        $this->setBanner($ws, $r, 'I', '   STATISTIK TRANSAKSI', self::C_WHITE, self::C_DARK, 28, 11);
        $ws->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;

        // Stats header
        $ws->mergeCells("A{$r}:D{$r}"); $ws->mergeCells("E{$r}:F{$r}"); $ws->mergeCells("G{$r}:I{$r}");
        foreach (["A{$r}" => 'METRIK', "E{$r}" => 'NILAI', "G{$r}" => 'KETERANGAN'] as $c => $v) {
            $ws->setCellValue($c, $v);
            $ws->getStyle($c)->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(self::C_WHITE);
            $ws->getStyle($c)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::C_SLATE);
            $ws->getStyle($c)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        }
        $ws->getRowDimension($r)->setRowHeight(22);
        $statHdr = $r; $r++;

        $surplusMonths = $monthly->where('net', '>=', 0)->count();
        $bestMonth     = $monthly->sortByDesc('net')->first();

        $statRows = [
            ['Total Seluruh Transaksi',          $kpis['txCount'] . ' Transaksi',     'Semua income + expense'],
            ['  Transaksi Pemasukan (Income)',    $kpis['incomeCount'] . ' Transaksi', ''],
            ['  Transaksi Pengeluaran (Expense)', $kpis['expenseCount'] . ' Transaksi',''],
            ['Rata-rata per Tx (Income)',         $fmt($kpis['avgIncome']),            'Nilai rata-rata transaksi income'],
            ['Rata-rata per Tx (Expense)',        $fmt($kpis['avgExpense']),           'Nilai rata-rata transaksi expense'],
            ['Income Terbesar (Single Tx)',       $fmt($kpis['maxIncome']),            'Pemasukan tertinggi dalam 1 transaksi'],
            ['Expense Terbesar (Single Tx)',      $fmt($kpis['maxExpense']),           'Pengeluaran tertinggi dalam 1 transaksi'],
            ['Bulan Surplus / Total',             $surplusMonths . ' / ' . $monthly->count() . ' Bulan', 'Cashflow positif'],
            ['Bulan Terbaik',                     $bestMonth ? ($bestMonth['month'] . ' - Net: ' . $fmt($bestMonth['net'])) : 'N/A', ''],
        ];

        foreach ($statRows as $i => $st) {
            $bg = $i % 2 === 0 ? self::C_WHITE : 'F8FAFC';
            $ws->mergeCells("A{$r}:D{$r}"); $ws->mergeCells("E{$r}:F{$r}"); $ws->mergeCells("G{$r}:I{$r}");
            $ws->setCellValue("A{$r}", $st[0]); $ws->setCellValue("E{$r}", $st[1]); $ws->setCellValue("G{$r}", $st[2]);
            $this->fillBg($ws, "A{$r}:D{$r}", $bg); $this->fillBg($ws, "E{$r}:F{$r}", $bg); $this->fillBg($ws, "G{$r}:I{$r}", $bg);
            $ws->getStyle("A{$r}")->getFont()->setSize(9)->getColor()->setRGB(self::C_DARK);
            $ws->getStyle("E{$r}")->getFont()->setSize(9)->setBold(true)->getColor()->setRGB(self::C_GREEN);
            $ws->getStyle("G{$r}")->getFont()->setSize(8)->getColor()->setRGB(self::C_SLATE);
            $ws->getStyle("A{$r}")->getAlignment()->setIndent(1)->setVertical(Alignment::VERTICAL_CENTER);
            $ws->getStyle("E{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $ws->getStyle("G{$r}")->getAlignment()->setIndent(1)->setVertical(Alignment::VERTICAL_CENTER);
            $ws->getRowDimension($r)->setRowHeight(22);
            $r++;
        }
        $this->setAllBorders($ws, "A{$statHdr}:I" . ($r - 1), self::C_BDR);
        $this->setOutline($ws, "A{$statHdr}:I" . ($r - 1), self::C_DARK);

        $ws->getColumnDimension('A')->setWidth(6);  $ws->getColumnDimension('B')->setWidth(22);
        $ws->getColumnDimension('C')->setWidth(14); $ws->getColumnDimension('D')->setWidth(14);
        $ws->getColumnDimension('E')->setWidth(20); $ws->getColumnDimension('F')->setWidth(14);
        $ws->getColumnDimension('G')->setWidth(16); $ws->getColumnDimension('H')->setWidth(20);
        $ws->getColumnDimension('I')->setWidth(18);
        $ws->freezePane('A3');
    }

    // ─── SHEET 2: TREN BULANAN ────────────────────────────────────────────

    private function buildMonthlyTrendsSheet(Spreadsheet $spreadsheet, \Illuminate\Support\Collection $monthly, string $periodLabel, string $currFormat, array $kpis): void
    {
        $spreadsheet->createSheet();
        $ws = $spreadsheet->setActiveSheetIndex(1)->setTitle('Tren Bulanan');
        $ws->getDefaultRowDimension()->setRowHeight(20);

        $surplusMonths = $monthly->where('net', '>=', 0)->count();
        $deficitMonths = $monthly->where('net', '<',  0)->count();
        $bestMonth     = $monthly->sortByDesc('net')->first();

        $this->setBanner($ws, 1, 'H', '   ANALISIS TREN BULANAN - ' . strtoupper($periodLabel), self::C_WHITE, self::C_DARK, 38, 14);
        $ws->getStyle('A1')->getFont()->setBold(true);
        $this->setBanner($ws, 2, 'H', '   Surplus: ' . $surplusMonths . ' bulan   |   Defisit: ' . $deficitMonths . ' bulan   |   Total: ' . $monthly->count() . ' bulan   |   Best: ' . ($bestMonth ? $bestMonth['month'] : 'N/A'), self::C_WHITE, self::C_GREEN, 22);

        $hdrs = ['No.', 'Bulan', 'Pemasukan', 'Pengeluaran', 'Net Cashflow', 'Saving Rate', 'Burn Rate', 'Status'];
        foreach ($hdrs as $ci => $h) {
            $col = Coordinate::stringFromColumnIndex($ci + 1);
            $this->applyHeader($ws, $col, 3, $h, self::C_WHITE, self::C_DARK);
        }
        $ws->getRowDimension(3)->setRowHeight(24);

        $r = 4;
        foreach ($monthly as $mi => $m) {
            $isSurplus = $m['net'] >= 0;
            $isBest    = $bestMonth && $m['month'] === $bestMonth['month'];
            $bg        = $isBest ? 'FFFBEB' : ($mi % 2 === 0 ? self::C_WHITE : self::C_BGALT);

            $ws->setCellValue("A{$r}", $mi + 1);
            $ws->setCellValue("B{$r}", $m['month']);
            $ws->setCellValue("C{$r}", $m['income']);
            $ws->setCellValue("D{$r}", $m['expense']);
            $ws->setCellValue("E{$r}", $m['net']);
            $ws->setCellValue("F{$r}", $m['save_rate'] / 100);
            $ws->setCellValue("G{$r}", $m['burn_rate'] / 100);
            $ws->setCellValue("H{$r}", $isSurplus ? ($isBest ? 'Surplus BEST' : 'Surplus') : 'Defisit');

            $ws->getStyle("C{$r}")->getNumberFormat()->setFormatCode($currFormat);
            $ws->getStyle("D{$r}")->getNumberFormat()->setFormatCode($currFormat);
            $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode($currFormat);
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode('0.0%');
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode('0.0%');

            $this->fillBg($ws, "A{$r}:H{$r}", $bg);
            $ws->getStyle("C{$r}")->getFont()->getColor()->setRGB(self::C_GREEN);
            $ws->getStyle("D{$r}")->getFont()->getColor()->setRGB(self::C_RED);
            $ws->getStyle("E{$r}")->getFont()->setBold(true)->getColor()->setRGB($isSurplus ? self::C_GREEN : self::C_RED);
            $ws->getStyle("H{$r}")->getFont()->setBold(true)->getColor()->setRGB($isSurplus ? self::C_GREEN : self::C_RED);
            $ws->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("C{$r}:G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getRowDimension($r)->setRowHeight(22);
            $r++;
        }

        // Total row
        $ws->setCellValue("B{$r}", 'TOTAL PERIODE');
        $ws->setCellValue("C{$r}", $kpis['totalIncome']);
        $ws->setCellValue("D{$r}", $kpis['totalExpense']);
        $ws->setCellValue("E{$r}", $kpis['netCashflow']);
        $ws->setCellValue("F{$r}", $kpis['savingRate'] / 100);
        $ws->setCellValue("G{$r}", $kpis['burnRate'] / 100);
        $ws->setCellValue("H{$r}", $kpis['netCashflow'] >= 0 ? 'SURPLUS' : 'DEFISIT');
        $ws->getStyle("C{$r}")->getNumberFormat()->setFormatCode($currFormat);
        $ws->getStyle("D{$r}")->getNumberFormat()->setFormatCode($currFormat);
        $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode($currFormat);
        $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode('0.0%');
        $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode('0.0%');
        $this->fillBg($ws, "A{$r}:H{$r}", 'EFF6FF');
        $ws->getStyle("A{$r}:H{$r}")->getFont()->setBold(true);
        $ws->getStyle("E{$r}")->getFont()->getColor()->setRGB($kpis['netCashflow'] >= 0 ? self::C_GREEN : self::C_RED);
        $ws->getStyle("H{$r}")->getFont()->getColor()->setRGB($kpis['netCashflow'] >= 0 ? self::C_GREEN : self::C_RED);
        $ws->getStyle("B{$r}:H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("C{$r}:G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getRowDimension($r)->setRowHeight(24);

        $this->setAllBorders($ws, "A3:H{$r}", self::C_BDR);
        $this->setOutline($ws, "A3:H{$r}", self::C_DARK);

        $ws->getColumnDimension('A')->setWidth(6);  $ws->getColumnDimension('B')->setWidth(14);
        $ws->getColumnDimension('C')->setWidth(22); $ws->getColumnDimension('D')->setWidth(22);
        $ws->getColumnDimension('E')->setWidth(22); $ws->getColumnDimension('F')->setWidth(14);
        $ws->getColumnDimension('G')->setWidth(14); $ws->getColumnDimension('H')->setWidth(16);
        $ws->freezePane('A4');
    }

    // ─── SHEET 3: KATEGORI ────────────────────────────────────────────────

    private function buildCategoryBreakdownSheet(Spreadsheet $spreadsheet, \Illuminate\Support\Collection $categoryStats, \Closure $fmt, string $currFormat): void
    {
        $spreadsheet->createSheet();
        $ws = $spreadsheet->setActiveSheetIndex(2)->setTitle('Breakdown Kategori');
        $ws->getDefaultRowDimension()->setRowHeight(20);

        $grandAll = (float) $categoryStats->sum('total');

        $this->setBanner($ws, 1, 'H', '   BREAKDOWN KATEGORI - RANKED BY TOTAL', self::C_WHITE, self::C_DARK, 38, 14);
        $ws->getStyle('A1')->getFont()->setBold(true);
        $this->setBanner($ws, 2, 'H', '   ' . $categoryStats->count() . ' Kategori Ditemukan   |   Grand Total: ' . $fmt($grandAll), self::C_WHITE, self::C_GREEN, 22);

        $hdrs = ['Rank', 'Kategori', 'Tipe', 'Jml Tx', 'Total (IDR)', 'Avg / Tx', '% dr Total', 'Label'];
        foreach ($hdrs as $ci => $h) {
            $col = Coordinate::stringFromColumnIndex($ci + 1);
            $this->applyHeader($ws, $col, 3, $h, self::C_WHITE, self::C_DARK);
        }
        $ws->getRowDimension(3)->setRowHeight(24);

        $r = 4;
        foreach ($categoryStats as $ci => $cat) {
            $isInc = $cat['type'] === 'income';
            $pct   = $grandAll > 0 ? round($cat['total'] / $grandAll * 100, 1) : 0;
            $bg    = $ci % 2 === 0 ? self::C_WHITE : self::C_BGALT;
            $tc    = $isInc ? self::C_GREEN : self::C_RED;
            $label = $ci === 0 ? 'Terbesar' : ($ci === $categoryStats->count() - 1 ? 'Terkecil' : '');

            $ws->setCellValue("A{$r}", $ci + 1);
            $ws->setCellValueExplicit("B{$r}", $this->sanitizeFormula($cat['name']), DataType::TYPE_STRING);
            $ws->setCellValueExplicit("C{$r}", ucfirst($cat['type']), DataType::TYPE_STRING);
            $ws->setCellValue("D{$r}", $cat['count']);
            $ws->setCellValue("E{$r}", $cat['total']);
            $ws->setCellValue("F{$r}", $cat['avg']);
            $ws->setCellValue("G{$r}", $pct / 100);
            $ws->setCellValueExplicit("H{$r}", $label, DataType::TYPE_STRING);

            $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode($currFormat);
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode($currFormat);
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode('0.0%');

            $this->fillBg($ws, "A{$r}:H{$r}", $bg);
            $ws->getStyle("A{$r}")->getFont()->setBold(true)->getColor()->setRGB(self::C_SLATE);
            $ws->getStyle("B{$r}")->getFont()->setBold(true)->getColor()->setRGB(self::C_DARK);
            $ws->getStyle("C{$r}")->getFont()->setBold(true)->getColor()->setRGB($tc);
            $ws->getStyle("E{$r}")->getFont()->setBold(true)->getColor()->setRGB($tc);
            $ws->getStyle("G{$r}")->getFont()->setBold(true);
            $ws->getStyle("H{$r}")->getFont()->setBold(true)->getColor()->setRGB($ci === 0 ? self::C_AMB : self::C_SLATE);
            $ws->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("C{$r}:D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("E{$r}:G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getRowDimension($r)->setRowHeight(22);
            $r++;
        }
        $this->setAllBorders($ws, "A3:H{$r}", self::C_BDR);
        $this->setOutline($ws, "A3:H{$r}", self::C_DARK);

        $ws->getColumnDimension('A')->setWidth(7);  $ws->getColumnDimension('B')->setWidth(26);
        $ws->getColumnDimension('C')->setWidth(14); $ws->getColumnDimension('D')->setWidth(10);
        $ws->getColumnDimension('E')->setWidth(22); $ws->getColumnDimension('F')->setWidth(20);
        $ws->getColumnDimension('G')->setWidth(13); $ws->getColumnDimension('H')->setWidth(12);
        $ws->freezePane('A4');
    }

    // ─── SHEET 4: DETAIL TRANSAKSI ────────────────────────────────────────

    private function buildTransactionDetailSheet(Spreadsheet $spreadsheet, Collection $transactions, array $kpis, \Closure $fmt, string $currFormat, float $exchangeRate): void
    {
        $spreadsheet->createSheet();
        $ws = $spreadsheet->setActiveSheetIndex(3)->setTitle('Detail Transaksi');
        $ws->getDefaultRowDimension()->setRowHeight(20);

        $this->setBanner($ws, 1, 'H', '   RINCIAN LENGKAP TRANSAKSI - ' . $kpis['txCount'] . ' TRANSAKSI', self::C_WHITE, self::C_DARK, 38, 14);
        $ws->getStyle('A1')->getFont()->setBold(true);
        $this->setBanner($ws, 2, 'H', '   Pemasukan: ' . $fmt($kpis['totalIncome']) . '   |   Pengeluaran: ' . $fmt($kpis['totalExpense']) . '   |   Net: ' . $fmt($kpis['netCashflow']), self::C_WHITE, $kpis['netCashflow'] >= 0 ? self::C_GREEN : self::C_RED, 22);

        $hdrs = ['No.', 'Tanggal', 'Tipe', 'Kategori', 'Akun', 'Jumlah', 'Bulan', 'Deskripsi'];
        foreach ($hdrs as $ci => $h) {
            $col = Coordinate::stringFromColumnIndex($ci + 1);
            $this->applyHeader($ws, $col, 3, $h, self::C_WHITE, self::C_DARK);
        }
        $ws->getRowDimension(3)->setRowHeight(24);

        $r = 4;
        foreach ($transactions as $ti => $t) {
            $isInc = $t->type === 'income';
            $bg    = $ti % 2 === 0 ? self::C_WHITE : self::C_BGALT;
            $tc    = $isInc ? self::C_GREEN : self::C_RED;

            $ws->setCellValue("A{$r}", $ti + 1);
            $ws->setCellValueExplicit("B{$r}", $t->transaction_date->format('d/m/Y'), DataType::TYPE_STRING);
            $ws->setCellValueExplicit("C{$r}", ucfirst($t->type), DataType::TYPE_STRING);
            $ws->setCellValueExplicit("D{$r}", $this->sanitizeFormula($t->category?->name ?? '-'), DataType::TYPE_STRING);
            $ws->setCellValueExplicit("E{$r}", $this->sanitizeFormula($t->account?->name  ?? '-'), DataType::TYPE_STRING);
            $ws->setCellValue("F{$r}", (float) $t->amount / $exchangeRate);
            $ws->setCellValueExplicit("G{$r}", $t->transaction_date->format('Y-m'), DataType::TYPE_STRING);
            $ws->setCellValueExplicit("H{$r}", $this->sanitizeFormula($t->description ?? ''), DataType::TYPE_STRING);

            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode($currFormat);

            $this->fillBg($ws, "A{$r}:H{$r}", $bg);
            $ws->getStyle("C{$r}")->getFont()->setBold(true)->getColor()->setRGB($tc);
            $ws->getStyle("F{$r}")->getFont()->setBold(true)->getColor()->setRGB($tc);
            $ws->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("B{$r}:C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getRowDimension($r)->setRowHeight(22);
            $r++;
        }
        $this->setAllBorders($ws, "A3:H{$r}", self::C_BDR);
        $this->setOutline($ws, "A3:H{$r}", self::C_DARK);
        $ws->setAutoFilter("A3:H3");

        $ws->getColumnDimension('A')->setWidth(7);  $ws->getColumnDimension('B')->setWidth(14);
        $ws->getColumnDimension('C')->setWidth(14); $ws->getColumnDimension('D')->setWidth(24);
        $ws->getColumnDimension('E')->setWidth(18); $ws->getColumnDimension('F')->setWidth(22);
        $ws->getColumnDimension('G')->setWidth(12); $ws->getColumnDimension('H')->setWidth(36);
        $ws->freezePane('A4');
    }

    // ─── STYLING HELPERS ──────────────────────────────────────────────────

    private function applyHeader($ws, string $col, int $row, string $txt, string $fgColor, string $bgColor, int $sz = 9): void
    {
        $cell = $ws->getCell($col . $row);
        $cell->setValue($txt);
        $cell->getStyle()->getFont()->setBold(true)->setSize($sz)->getColor()->setRGB($fgColor);
        $cell->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgColor);
        $cell->getStyle()->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function setOutline($ws, string $range, string $color): void
    {
        $ws->getStyle($range)->getBorders()->getOutline()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($color);
    }

    private function setAllBorders($ws, string $range, string $color): void
    {
        $ws->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($color);
    }

    private function fillBg($ws, string $range, string $color): void
    {
        $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
    }

    private function setBanner($ws, int $row, string $cols, string $txt, string $fgColor, string $bgColor, int $h, int $sz = 9): void
    {
        $ws->mergeCells("A{$row}:{$cols}{$row}");
        $ws->getCell("A{$row}")->setValue($txt);
        $ws->getRowDimension($row)->setRowHeight($h);
        $ws->getStyle("A{$row}")->getFont()->setSize($sz)->getColor()->setRGB($fgColor);
        $ws->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgColor);
        $ws->getStyle("A{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * Sanitize user input strings against CSV/Excel Formula Injection (CWE-1236).
     * Prefixes a single quote if string starts with formula trigger characters: =, +, -, @, tab, newline.
     */
    private function sanitizeFormula(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $firstChar = substr($value, 0, 1);
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
