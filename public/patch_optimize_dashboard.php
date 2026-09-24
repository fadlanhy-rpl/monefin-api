<?php
/**
 * MoneFin — Patch: Optimize Dashboard Controller Queries & Cache
 *
 * Update:
 * - 1 income/expense aggregation query instead of 2 separate queries
 * - 1 weekly trend query using UNION ALL instead of 2 separate queries
 * - 1 monthly trend query using UNION ALL instead of 2 separate queries
 * - Cache cleanup
 *
 * Upload to : monefin-backend/public/patch_optimize_dashboard.php
 * Access via: https://sk0010uoic.skipper.my.id/patch_optimize_dashboard.php
 * DELETE this file after use!
 */

header('Content-Type: text/plain; charset=utf-8');

$base = dirname(__DIR__);
echo "=== MoneFin Patch: Optimize Dashboard Controller Queries ===\n\n";

$targetFile = $base . '/app/Http/Controllers/DashboardController.php';

$newContent = <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\GamificationService;
use App\Services\SpendingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        private SpendingAnalysisService $spending,
        private GamificationService $gamification
    ) {}

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

        // Rekam aksi misi evaluasi / review finansial (hanya sekali per hari per user agar tidak membebani database)
        $questRecordedTodayKey = "quest_recorded:{$user->id}:check_analytics:" . now()->toDateString();
        if (!Cache::has($questRecordedTodayKey)) {
            $this->gamification->recordQuestAction($user, 'check_analytics', 1);
            Cache::put($questRecordedTodayKey, true, now()->endOfDay());
        }

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

        // 2. Total income & expense pada rentang tanggal terpilih (1 query, bukan 2)
        $incomeExpenseRow = Transaction::where('user_id', $user->id)
            ->whereBetween('transaction_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as total_income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as total_expense
            ")
            ->first();

        $totalIncomeThisMonth  = (float) ($incomeExpenseRow->total_income  ?? 0);
        $totalExpenseThisMonth = (float) ($incomeExpenseRow->total_expense ?? 0);

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


        // 6. Weekly Trend (Sen - Min) - 1 query UNION untuk minggu ini + minggu lalu
        $startOfThisWeek = now()->startOfWeek();
        $endOfThisWeek   = now()->endOfWeek();
        $startOfLastWeek = now()->subWeek()->startOfWeek();
        $endOfLastWeek   = now()->subWeek()->endOfWeek();

        // Satu query UNION menggantikan 2 query terpisah
        $weeklyRows = DB::select("
            SELECT transaction_date, SUM(amount) as total, 'this' as week_label
            FROM transactions
            WHERE user_id = ? AND type = 'expense' AND deleted_at IS NULL
              AND transaction_date BETWEEN ? AND ?
            GROUP BY transaction_date
            UNION ALL
            SELECT transaction_date, SUM(amount) as total, 'last' as week_label
            FROM transactions
            WHERE user_id = ? AND type = 'expense' AND deleted_at IS NULL
              AND transaction_date BETWEEN ? AND ?
            GROUP BY transaction_date
        ", [
            $user->id, $startOfThisWeek->toDateString(), $endOfThisWeek->toDateString(),
            $user->id, $startOfLastWeek->toDateString(), $endOfLastWeek->toDateString(),
        ]);

        $thisWeekGrouped = [];
        $lastWeekGrouped = [];
        foreach ($weeklyRows as $row) {
            $dow = \Carbon\Carbon::parse($row->transaction_date)->dayOfWeek + 1;
            if ($row->week_label === 'this') {
                $thisWeekGrouped[$dow] = ($thisWeekGrouped[$dow] ?? 0) + (float) $row->total;
            } else {
                $lastWeekGrouped[$dow] = ($lastWeekGrouped[$dow] ?? 0) + (float) $row->total;
            }
        }

        $daysMap   = [2 => 'Sen', 3 => 'Sel', 4 => 'Rab', 5 => 'Kam', 6 => 'Jum', 7 => 'Sab', 1 => 'Min'];

        // Cari nilai pengeluaran maksimum di seluruh hari (minggu ini & minggu lalu)
        $maxWeekly = 0;
        foreach ([2, 3, 4, 5, 6, 7, 1] as $idx) {
            $thisAmt = (float) ($thisWeekGrouped[$idx] ?? 0);
            $lastAmt = (float) ($lastWeekGrouped[$idx] ?? 0);
            if ($thisAmt > $maxWeekly) $maxWeekly = $thisAmt;
            if ($lastAmt > $maxWeekly) $maxWeekly = $lastAmt;
        }
        $maxWeekly = max($maxWeekly, 1);

        $weeklyTrend = [];
        foreach ([2, 3, 4, 5, 6, 7, 1] as $idx) {
            $thisAmt = (float) ($thisWeekGrouped[$idx] ?? 0);
            $lastAmt = (float) ($lastWeekGrouped[$idx] ?? 0);

            $weeklyTrend[] = [
                'label'    => $daysMap[$idx],
                'thisAmt'  => $thisAmt,
                'lastAmt'  => $lastAmt,
                'thisWeek' => round(($thisAmt / $maxWeekly) * 100),
                'last'     => round(($lastAmt / $maxWeekly) * 100),
            ];
        }

        // 7. Monthly Trend (Past 6 months) - 1 query UNION untuk tahun ini + tahun lalu
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

        // Satu query UNION menggantikan 2 query terpisah
        $monthlyRows = DB::select("
            SELECT {$monthExpr} as period, SUM(amount) as total, 'this' as year_label
            FROM transactions
            WHERE user_id = ? AND type = 'expense' AND deleted_at IS NULL
              AND transaction_date BETWEEN ? AND ?
            GROUP BY {$monthExpr}
            UNION ALL
            SELECT {$monthExpr} as period, SUM(amount) as total, 'last' as year_label
            FROM transactions
            WHERE user_id = ? AND type = 'expense' AND deleted_at IS NULL
              AND transaction_date BETWEEN ? AND ?
            GROUP BY {$monthExpr}
        ", [
            $user->id, $startMonth->toDateString(), $endMonth->toDateString(),
            $user->id, $startMonthLastYear->toDateString(), $endMonthLastYear->toDateString(),
        ]);

        $thisYearMonthSums = [];
        $lastYearMonthSums = [];
        foreach ($monthlyRows as $row) {
            if ($row->year_label === 'this') {
                $thisYearMonthSums[$row->period] = (float) $row->total;
            } else {
                $lastYearMonthSums[$row->period] = (float) $row->total;
            }
        }

        // Cari nilai pengeluaran maksimum di seluruh 6 bulan (tahun ini & tahun lalu)
        $maxMonthly = 0;
        for ($i = 0; $i < 6; $i++) {
            $currentDate       = $startMonth->copy()->addMonths($i);
            $periodKeyThisYear = $currentDate->format('Y-m');
            $periodKeyLastYear = $currentDate->copy()->subYear()->format('Y-m');

            $thisAmt = (float) ($thisYearMonthSums[$periodKeyThisYear] ?? 0);
            $lastAmt = (float) ($lastYearMonthSums[$periodKeyLastYear] ?? 0);
            if ($thisAmt > $maxMonthly) $maxMonthly = $thisAmt;
            if ($lastAmt > $maxMonthly) $maxMonthly = $lastAmt;
        }
        $maxMonthly = max($maxMonthly, 1);

        $monthlyTrend = [];
        for ($i = 0; $i < 6; $i++) {
            $currentDate       = $startMonth->copy()->addMonths($i);
            $periodKeyThisYear = $currentDate->format('Y-m');
            $periodKeyLastYear = $currentDate->copy()->subYear()->format('Y-m');

            $m          = $currentDate->month;
            $monthLabel = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][$m - 1];

            $thisAmt = (float) ($thisYearMonthSums[$periodKeyThisYear] ?? 0);
            $lastAmt = (float) ($lastYearMonthSums[$periodKeyLastYear] ?? 0);

            $monthlyTrend[] = [
                'label'    => $monthLabel,
                'thisAmt'  => $thisAmt,
                'lastAmt'  => $lastAmt,
                'thisWeek' => round(($thisAmt / $maxMonthly) * 100),
                'last'     => round(($lastAmt / $maxMonthly) * 100),
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
PHP;

// ── Write file ──────────────────────────────────────────────────────────────
echo "Target: {$targetFile}\n";

if (!file_exists($targetFile)) {
    echo "❌ ERROR: File tidak ditemukan: {$targetFile}\n";
    exit(1);
}

// Backup file lama
$backupFile = $targetFile . '.bak_' . date('YmdHis');
if (!copy($targetFile, $backupFile)) {
    echo "⚠ WARNING: Gagal membuat backup, melanjutkan...\n";
} else {
    echo "✅ Backup dibuat: {$backupFile}\n";
}

// Tulis file baru
$written = file_put_contents($targetFile, $newContent);
if ($written === false) {
    echo "❌ ERROR: Gagal menulis file. Periksa permission folder.\n";
    exit(1);
}

echo "✅ DashboardController.php berhasil diperbarui ({$written} bytes ditulis)\n\n";

// ── Clear Laravel cache ──────────────────────────────────────────────────────
echo "── Membersihkan cache Laravel... ──\n";

$configCache = $base . '/bootstrap/cache/config.php';
if (file_exists($configCache)) {
    unlink($configCache);
    echo "✅ Config cache dihapus\n";
} else {
    echo "ℹ Config cache tidak ada (sudah bersih)\n";
}

$servicesCache = $base . '/bootstrap/cache/services.php';
if (file_exists($servicesCache)) {
    unlink($servicesCache);
    echo "✅ Services cache dihapus\n";
}

$packagesCache = $base . '/bootstrap/cache/packages.php';
if (file_exists($packagesCache)) {
    unlink($packagesCache);
    echo "✅ Packages cache dihapus\n";
}

$cacheDir = $base . '/storage/framework/cache/data';
if (is_dir($cacheDir)) {
    $cacheFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $cleared = 0;
    foreach ($cacheFiles as $f) {
        if ($f->isFile()) {
            unlink($f->getPathname());
            $cleared++;
        }
    }
    echo "✅ App cache dihapus ({$cleared} files)\n";
}

// ── Verify ───────────────────────────────────────────────────────────────────
echo "\n── Verifikasi ──\n";
$content = file_get_contents($targetFile);
if (str_contains($content, "'this' as week_label") && str_contains($content, "'this' as year_label")) {
    echo "✅ Optimasi query dashboard berhasil diterapkan!\n";
    echo "\n=== SELESAI ===\n";
    echo "Backend SkipperHost sudah teroptimasi.\n";
    echo "Silakan HAPUS file patch_optimize_dashboard.php ini setelah selesai!\n";
} else {
    echo "❌ Verifikasi GAGAL — konten file tidak sesuai ekspektasi.\n";
}
