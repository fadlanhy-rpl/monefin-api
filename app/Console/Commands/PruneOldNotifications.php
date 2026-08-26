<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SpendingNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PruneOldNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:prune-old {--days=30 : Jumlah hari retensi notifikasi}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Hapus otomatis notifikasi yang berumur lebih dari 30 hari';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $days = 30;
        }

        $cutoffDate = Carbon::now()->subDays($days);
        $this->info("Menghapus notifikasi yang dibuat sebelum: {$cutoffDate->toDateTimeString()} ({$days} hari lalu)...");

        $deletedCount = SpendingNotification::where('created_at', '<', $cutoffDate)->delete();

        $this->info("Berhasil menghapus {$deletedCount} notifikasi lama.");
        Log::info("Pruned {$deletedCount} notifications older than {$days} days.");

        return Command::SUCCESS;
    }
}
