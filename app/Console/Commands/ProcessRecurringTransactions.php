<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\IncomeSetting;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
                // Defense-in-depth: Validate account ownership
                $account = null;
                if ($setting->account_id) {
                    $account = \App\Models\Account::where('id', $setting->account_id)
                        ->where('user_id', $setting->user_id)
                        ->first();

                    if (!$account) {
                        Log::warning("Security violation / Ownership mismatch: Setting #{$setting->id} (User #{$setting->user_id}) references account #{$setting->account_id} which does not belong to this user. Processing skipped.", [
                            'setting_id' => $setting->id,
                            'setting_user_id' => $setting->user_id,
                            'account_id' => $setting->account_id,
                        ]);
                        $this->warn("Skipped setting #{$setting->id}: Account #{$setting->account_id} does not belong to User #{$setting->user_id}");
                        $setting->update(['last_processed_date' => $today->toDateString()]);
                        continue;
                    }
                } else {
                    $account = \App\Models\Account::where('user_id', $setting->user_id)->first();
                }

                // Defense-in-depth: Validate category ownership
                $category = null;
                if ($setting->category_id) {
                    $category = \App\Models\Category::where('id', $setting->category_id)
                        ->where(function ($query) use ($setting) {
                            $query->where('user_id', $setting->user_id)
                                  ->orWhereNull('user_id');
                        })
                        ->first();

                    if (!$category) {
                        Log::warning("Security violation / Ownership mismatch: Setting #{$setting->id} (User #{$setting->user_id}) references category #{$setting->category_id} which does not belong to this user. Processing skipped.", [
                            'setting_id' => $setting->id,
                            'setting_user_id' => $setting->user_id,
                            'category_id' => $setting->category_id,
                        ]);
                        $this->warn("Skipped setting #{$setting->id}: Category #{$setting->category_id} does not belong to User #{$setting->user_id}");
                        $setting->update(['last_processed_date' => $today->toDateString()]);
                        continue;
                    }
                } else {
                    $category = \App\Models\Category::where('user_id', $setting->user_id)
                        ->where('type', $setting->type ?? 'income')
                        ->first();
                }

                // If we still can't find account or category, skip and log error
                if (!$account || !$category) {
                    Log::error("Skipped setting #{$setting->id} due to missing account or category for User #{$setting->user_id}.");
                    $setting->update(['last_processed_date' => $today->toDateString()]);
                    continue;
                }

                // Generate Transaksi & Update saldo akun secara atomik
                DB::transaction(function () use ($setting, $account, $category, $today) {
                    Transaction::create([
                        'user_id'          => $setting->user_id,
                        'account_id'       => $account->id,
                        'category_id'      => $category->id,
                        'type'             => $setting->type ?? 'income',
                        'amount'           => $setting->amount,
                        'description'      => $setting->title ?? 'Transaksi Rutin (' . ucfirst($setting->period_type) . ')',
                        'transaction_date' => $today->toDateString(),
                    ]);

                    if (($setting->type ?? 'income') === 'income') {
                        $account->increment('balance', $setting->amount);
                    } else {
                        $account->decrement('balance', $setting->amount);
                    }

                    $setting->update(['last_processed_date' => $today->toDateString()]);
                });

                $processedCount++;
                $this->info("Processed setting #{$setting->id} for User #{$setting->user_id}");
                Log::info("Recurring transaction processed for setting #{$setting->id}");
            }
        }

        $this->info("Done. Processed {$processedCount} transactions.");
    }
}
