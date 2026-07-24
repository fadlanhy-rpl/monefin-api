<?php

namespace App\Services;

use App\Models\IncomeSetting;
use App\Models\SpendingNotification;
use App\Models\SpendingThreshold;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class SpendingAnalysisService
{
    /**
     * Hitung status pengeluaran user pada periode berjalan.
     * Return array siap dipakai controller / dashboard.
     */
    public function analyze(User $user): array
    {
        // 1. Ambil income setting aktif
        $setting = $user->activeIncomeSetting()->first();

        if (! $setting || $setting->amount <= 0) {
            return $this->emptyResult('income_setting_not_found');
        }

        // 2. Tentukan rentang tanggal & label periode
        [$startDate, $endDate, $periodLabel] = $this->resolvePeriod($setting);

        // 3. Hitung total pengeluaran di periode tersebut
        $spent = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount');

        // 4. Ambil threshold (gunakan default jika belum diset)
        $threshold = $user->spendingThreshold ?? new SpendingThreshold([
            'hemat_max_percent' => 60.00,
            'boros_min_percent' => 85.00,
        ]);

        // 5. Hitung persentase
        $percent = round(($spent / $setting->amount) * 100, 2);

        // 6. Klasifikasi status
        [$status, $message] = $this->classify($percent, $threshold);

        return [
            'status'       => $status,
            'spent_percent'=> $percent,
            'spent_amount' => (float) $spent,
            'income_amount'=> (float) $setting->amount,
            'period_type'  => $setting->period_type,
            'period_label' => $periodLabel,
            'message'      => $message,
        ];
    }

    /**
     * Simpan notifikasi setelah transaksi expense dibuat/diedit/dihapus.
     * Hanya simpan jika status berubah dari notifikasi terakhir di periode ini.
     */
    public function recordNotification(User $user): void
    {
        $result = $this->analyze($user);

        if ($result['status'] === 'income_setting_not_found') {
            return;
        }

        // Cek apakah sudah ada notifikasi dengan status yang sama di periode ini
        $existing = SpendingNotification::where('user_id', $user->id)
            ->where('period_label', $result['period_label'])
            ->latest()
            ->first();

        if ($existing && $existing->type === $result['status']) {
            // Status tidak berubah, update angka tapi tidak buat record baru
            $existing->update([
                'spent_percent' => $result['spent_percent'],
                'message'       => $result['message'],
            ]);
            return;
        }

        SpendingNotification::create([
            'user_id'      => $user->id,
            'type'         => $result['status'],
            'period_type'  => $result['period_type'],
            'period_label' => $result['period_label'],
            'spent_percent'=> $result['spent_percent'],
            'message'      => $result['message'],
            'is_read'      => false,
        ]);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function resolvePeriod(IncomeSetting $setting): array
    {
        $now = Carbon::now();

        if ($setting->period_type === 'weekly') {
            $startDate   = $now->copy()->startOfWeek()->toDateString();
            $endDate     = $now->copy()->endOfWeek()->toDateString();
            $periodLabel = $now->format('Y-\WW');
        } else {
            // monthly
            $startDate   = $now->copy()->startOfMonth()->toDateString();
            $endDate     = $now->copy()->endOfMonth()->toDateString();
            $periodLabel = $now->format('Y-m');
        }

        return [$startDate, $endDate, $periodLabel];
    }

    private function classify(float $percent, SpendingThreshold $threshold): array
    {
        $hematMax = (float) $threshold->hemat_max_percent;
        $borosMin = (float) $threshold->boros_min_percent;

        if ($percent <= $hematMax) {
            return ['hemat', "Keren! Pengeluaranmu baru {$percent}% dari uang saku. Kamu sangat hemat bulan ini! 🎉"];
        }

        if ($percent > $borosMin) {
            return ['boros', "Hati-hati! Pengeluaranmu sudah mencapai {$percent}% dari uang saku. Kurangi pengeluaran yang tidak perlu. ⚠️"];
        }

        return ['normal', "Pengeluaranmu ada di {$percent}% dari uang saku. Tetap terkontrol ya! 👍"];
    }

    private function emptyResult(string $reason): array
    {
        return [
            'status'       => $reason,
            'spent_percent'=> 0,
            'spent_amount' => 0,
            'income_amount'=> 0,
            'period_type'  => null,
            'period_label' => null,
            'message'      => 'Silakan atur uang saku terlebih dahulu di halaman Settings.',
        ];
    }
}
