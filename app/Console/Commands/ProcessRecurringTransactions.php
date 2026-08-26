<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\IncomeSetting;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessRecurringTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:process-recurring';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Proses pencatatan otomatis transaksi rutin (Income Settings)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::today();
        $this->info("Processing recurring transactions for {$today->toDateString()}...");

        // Ambil semua setting yang aktif dan efektif per hari ini atau sebelumnya
        $settings = IncomeSetting::where('is_active', true)
            ->where(function($query) use ($today) {
                $query->whereNull('effective_date')
                      ->orWhere('effective_date', '<=', $today);
            })
            ->get();

        $processedCount = 0;

        foreach ($settings as $setting) {
            $shouldProcess = false;

            // Kapan transaksi ini harusnya dicatat?
            $lastProcessed = $setting->last_processed_date ? Carbon::parse($setting->last_processed_date) : null;
            $effectiveDate = $setting->effective_date ? Carbon::parse($setting->effective_date) : $today->copy();

            if (!$lastProcessed) {
                // Jika belum pernah diproses, dan effective date adalah hari ini atau masa lalu
                if ($effectiveDate->lessThanOrEqualTo($today)) {
                    $shouldProcess = true;
                }
            } else {
                // Cek berdasarkan periode
                if ($setting->period_type === 'daily') {
                    if ($lastProcessed->diffInDays($today) >= 1) $shouldProcess = true;
                } elseif ($setting->period_type === 'weekly') {
                    if ($lastProcessed->diffInWeeks($today) >= 1) $shouldProcess = true;
                } elseif ($setting->period_type === 'monthly') {
                    if ($lastProcessed->diffInMonths($today) >= 1) $shouldProcess = true;
                }
            }

            if ($shouldProcess) {
                // Ensure account_id and category_id are not null for transactions table
                $accountId = $setting->account_id;
                if (!$accountId) {
                    $firstAccount = \App\Models\Account::where('user_id', $setting->user_id)->first();
                    $accountId = $firstAccount ? $firstAccount->id : null;
                }

                $categoryId = $setting->category_id;
                if (!$categoryId) {
                    $firstCategory = \App\Models\Category::where('user_id', $setting->user_id)
                        ->where('type', $setting->type ?? 'income')
                        ->first();
                    $categoryId = $firstCategory ? $firstCategory->id : null;
                }

                // If we still can't find account/category, skip and log error
                if (!$accountId || !$categoryId) {
                    Log::error("Skipped setting #{$setting->id} due to missing account or category.");
                    // Update last_processed_date anyway to prevent infinite loop
                    $setting->update(['last_processed_date' => $today->toDateString()]);
                    continue;
                }

                // Generate Transaksi
                Transaction::create([
                    'user_id' => $setting->user_id,
                    'account_id' => $accountId,
                    'category_id' => $categoryId,
                    'type' => $setting->type ?? 'income', // default income for backward compat
                    'amount' => $setting->amount,
                    'description' => $setting->title ?? 'Transaksi Rutin (' . ucfirst($setting->period_type) . ')',
                    'transaction_date' => $today->toDateString(),
                ]);

                // Update saldo akun (AccountBalance)
                $account = \App\Models\Account::find($accountId);
                if ($account) {
                    if (($setting->type ?? 'income') === 'income') {
                        $account->increment('balance', $setting->amount);
                    } else {
                        $account->decrement('balance', $setting->amount);
                    }
                }

                // Update last_processed_date
                $setting->update(['last_processed_date' => $today->toDateString()]);

                $processedCount++;
                $this->info("Processed setting #{$setting->id} for User #{$setting->user_id}");
                Log::info("Recurring transaction processed for setting #{$setting->id}");
            }
        }

        $this->info("Done. Processed {$processedCount} transactions.");
    }
}
