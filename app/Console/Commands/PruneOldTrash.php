<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Budget;
use App\Models\SplitBill;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PruneOldTrash extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trash:prune-old {--days=30 : Jumlah hari retensi data di tempat sampah}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Hapus permanen otomatis data di tempat sampah yang sudah lebih dari 30 hari';

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
        $this->info("Menghapus data tempat sampah yang dihapus sebelum: {$cutoffDate->toDateTimeString()} ({$days} hari lalu)...");

        $deletedAccounts = Account::onlyTrashed()->where('deleted_at', '<', $cutoffDate)->forceDelete();
        $deletedTransactions = Transaction::onlyTrashed()->where('deleted_at', '<', $cutoffDate)->forceDelete();
        $deletedCategories = Category::onlyTrashed()->where('deleted_at', '<', $cutoffDate)->forceDelete();
        $deletedGoals = Goal::onlyTrashed()->where('deleted_at', '<', $cutoffDate)->forceDelete();
        $deletedBudgets = Budget::onlyTrashed()->where('deleted_at', '<', $cutoffDate)->forceDelete();
        $deletedSplitBills = SplitBill::onlyTrashed()->where('deleted_at', '<', $cutoffDate)->forceDelete();

        $totalDeleted = $deletedAccounts + $deletedTransactions + $deletedCategories + $deletedGoals + $deletedBudgets + $deletedSplitBills;

        $this->info("Berhasil menghapus permanen {$totalDeleted} item tempat sampah lama.");
        Log::info("Pruned {$totalDeleted} trashed items older than {$days} days (Accounts: {$deletedAccounts}, Transactions: {$deletedTransactions}, Categories: {$deletedCategories}, Goals: {$deletedGoals}, Budgets: {$deletedBudgets}, SplitBills: {$deletedSplitBills}).");

        return Command::SUCCESS;
    }
}
