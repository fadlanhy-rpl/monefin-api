<?php

namespace App\Jobs;

use App\Mail\BudgetAlertMail;
use App\Models\Budget;
use App\Models\SpendingNotification;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GamificationService;
use App\Services\SmartInsightService;
use App\Services\SpendingAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Menangani semua side effects setelah operasi transaksi:
 * - Analisis spending & notifikasi threshold
 * - Budget alert (notifikasi + email async)
 * - Gamifikasi (XP, streak, achievement progress)
 * - Invalidasi cache (SmartInsight, Dashboard)
 *
 * Dijalankan di background worker sehingga HTTP response kembali instan.
 */
class ProcessTransactionSideEffects implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maksimal percobaan ulang jika job gagal.
     * Dibatasi 2 kali — gamification & notif tidak critical jika gagal sekali.
     */
    public int $tries = 2;

    /**
     * Timeout per percobaan (detik).
     */
    public int $timeout = 60;

    /**
     * Jeda antar retry (detik).
     */
    public array $backoff = [10, 30];

    /**
     * @param User        $user        User pemilik transaksi
     * @param Transaction $transaction Transaksi yang baru dibuat/diubah/dihapus
     * @param string      $action      'store' | 'update' | 'delete'
     * @param array       $preferences User preferences snapshot (untuk cek txAlert, budgetAlert)
     */
    public function __construct(
        public readonly User        $user,
        public readonly Transaction $transaction,
        public readonly string      $action = 'store',
        public readonly array       $preferences = [],
    ) {}

    public function handle(
        GamificationService    $gamification,
        SpendingAnalysisService $spending,
    ): void {
        // 1. Rekam analisis spending
        $this->runSafe(
            fn() => $spending->recordNotification($this->user),
            'SpendingAnalysis',
        );

        // 2. txAlert — notifikasi transaksi individual (hanya saat store)
        if ($this->action === 'store' && ($this->preferences['txAlert'] ?? false)) {
            $this->runSafe(
                fn() => $this->createTxAlert(),
                'TxAlert',
            );
        }

        // 3. Budget alert — cek threshold & kirim email (hanya saat store, type expense)
        if (
            $this->action === 'store'
            && ($this->preferences['budgetAlert'] ?? false)
            && $this->transaction->type === 'expense'
        ) {
            $this->runSafe(
                fn() => $this->processBudgetAlert(),
                'BudgetAlert',
            );
        }

        // 4. Gamifikasi — hanya saat store
        if ($this->action === 'store') {
            $this->runSafe(
                fn() => $this->processGamification($gamification),
                'Gamification',
            );
        }

        // 5. Invalidasi cache SmartInsight & Dashboard
        $this->runSafe(
            fn() => $this->invalidateCaches(),
            'CacheInvalidation',
        );
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private function createTxAlert(): void
    {
        $tx       = $this->transaction;
        $sign     = $tx->type === 'income' ? '+' : '-';
        $amount   = number_format($tx->amount, 0, ',', '.');
        $catName  = $tx->category?->name ?? 'Tanpa Kategori';

        SpendingNotification::create([
            'user_id'       => $this->user->id,
            'type'          => 'transaction_alert',
            'period_type'   => 'daily',
            'period_label'  => date('Y-m-d'),
            'spent_percent' => 0,
            'message'       => "Transaksi baru: {$sign}{$amount} ({$catName})",
            'is_read'       => false,
        ]);
    }

    private function processBudgetAlert(): void
    {
        $tx = $this->transaction;

        $budget = Budget::where('user_id', $this->user->id)
            ->where('category_id', $tx->category_id)
            ->where('month', date('n'))
            ->where('year', date('Y'))
            ->first();

        if (!$budget || $budget->limit_amount <= 0) {
            return;
        }

        $totalSpent = Transaction::where('user_id', $this->user->id)
            ->where('category_id', $tx->category_id)
            ->where('type', 'expense')
            ->whereYear('transaction_date', date('Y'))
            ->whereMonth('transaction_date', date('m'))
            ->sum('amount');

        $percent = ($totalSpent / $budget->limit_amount) * 100;

        $thresholdLevel = match (true) {
            $percent >= 100 => 100,
            $percent >= 80  => 80,
            default         => null,
        };

        if ($thresholdLevel === null) {
            return;
        }

        $periodLabel = "cat_{$tx->category_id}_" . date('Y-m') . "_{$thresholdLevel}";

        // Cegah duplikasi alert untuk threshold yang sama di bulan yang sama
        $alreadySent = SpendingNotification::where('user_id', $this->user->id)
            ->where('type', 'budget_alert')
            ->where('period_label', $periodLabel)
            ->exists();

        if ($alreadySent) {
            return;
        }

        $catName    = $tx->category?->name ?? 'Kategori';
        $isCritical = $thresholdLevel === 100;
        $message    = $isCritical
            ? "Peringatan Kritis: Pengeluaran {$catName} telah mencapai 100% dari limit bulan ini!"
            : "Peringatan: Pengeluaran {$catName} mencapai " . round($percent) . "% dari limit bulan ini!";

        SpendingNotification::create([
            'user_id'       => $this->user->id,
            'type'          => 'budget_alert',
            'period_type'   => 'monthly',
            'period_label'  => $periodLabel,
            'spent_percent' => $percent,
            'message'       => $message,
            'is_read'       => false,
        ]);

        // Kirim email secara async (BudgetAlertMail implements ShouldQueue)
        Mail::to($this->user->email)->queue(
            new BudgetAlertMail(
                $this->user->name,
                $catName,
                $percent,
                $totalSpent,
                $budget->limit_amount,
            )
        );
    }

    private function processGamification(GamificationService $gamification): void
    {
        $gamification->awardXP($this->user, 10, 'Mencatat Transaksi');
        $gamification->recordActivity($this->user);
        $gamification->recordQuestAction($this->user, 'record_transactions', 1);
        $gamification->updateAchievementProgress($this->user, 'first_tx', 1);

        $totalTxCount = Transaction::where('user_id', $this->user->id)->count();
        $gamification->updateAchievementProgress($this->user, 'tx_10', $totalTxCount);
        $gamification->updateAchievementProgress($this->user, 'tx_50', $totalTxCount);
        $gamification->updateAchievementProgress($this->user, 'tx_100', $totalTxCount);
    }

    private function invalidateCaches(): void
    {
        // SmartInsight cache (per page, per language)
        SmartInsightService::invalidateUserCache($this->user);

        // Dashboard summary cache (semua range preset yang didukung)
        foreach (['7days', '30days', 'this_month', 'this_year'] as $range) {
            Cache::forget("dashboard_summary:{$this->user->id}:{$range}");
        }

        // AI context cache — agar pesan chat AI berikutnya pakai data finansial terbaru
        Cache::forget("ai_context:{$this->user->id}");
    }


    /**
     * Wrapper agar satu sub-task gagal tidak membatalkan seluruh job.
     */
    private function runSafe(callable $fn, string $context): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error("ProcessTransactionSideEffects [{$context}] failed", [
                'user_id'        => $this->user->id,
                'transaction_id' => $this->transaction->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
