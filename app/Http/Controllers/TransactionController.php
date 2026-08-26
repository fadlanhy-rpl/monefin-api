<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\SpendingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\BudgetAlertMail;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function __construct(
        private SpendingAnalysisService $spending,
        private \App\Services\GamificationService $gamification
    ) {}

    /**
     * List transaksi dengan filter: tanggal, kategori, akun, tipe, pencarian teks.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Transaction::where('user_id', $request->user()->id)
            ->with(['account', 'category'])
            ->when($request->start_date, fn ($q) => $q->where('transaction_date', '>=', $request->start_date))
            ->when($request->end_date,   fn ($q) => $q->where('transaction_date', '<=', $request->end_date))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->account_id,  fn ($q) => $q->where('account_id', $request->account_id))
            ->when($request->search, function ($q) use ($request) {
                $search = $request->search;
                $operator = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(function ($sq) use ($search, $operator) {
                    $sq->where('description', $operator, "%{$search}%")
                       ->orWhereHas('category', fn($c) => $c->where('name', $operator, "%{$search}%"));
                });
            })
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc');

        // Paginate: default 20 per halaman
        $perPage = $request->per_page ?? 20;

        return TransactionResource::collection($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id'       => ['required', 'exists:accounts,id'],
            'category_id'      => ['required', 'exists:categories,id'],
            'goal_id'          => ['nullable', 'exists:goals,id'],
            'type'             => ['required', 'in:income,expense'],
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'description'      => ['nullable', 'string', 'max:500'],
            'transaction_date' => ['required', 'date'],
        ]);

        $validated['user_id'] = $request->user()->id;

        $transaction = Transaction::create($validated);

        // Update saldo akun
        $this->updateAccountBalance($transaction->account_id, $transaction->type, $transaction->amount);

        // Rekam analisis spending (overall)
        $this->spending->recordNotification($request->user());

        // --- Fitur Preferences: txAlert ---
        $preferences = $request->user()->preferences ?? [];
        if (isset($preferences['txAlert']) && $preferences['txAlert'] === true) {
            \App\Models\SpendingNotification::create([
                'user_id' => $request->user()->id,
                'type' => 'transaction_alert',
                'period_type' => 'daily',
                'period_label' => date('Y-m-d'),
                'spent_percent' => 0,
                'message' => 'Transaksi baru: ' . ($transaction->type === 'income' ? '+' : '-') . number_format($transaction->amount, 0, ',', '.') . ' (' . ($transaction->category->name ?? 'Tanpa Kategori') . ')',
                'is_read' => false,
            ]);
        }

        // --- Fitur Preferences: budgetAlert ---
        if (isset($preferences['budgetAlert']) && $preferences['budgetAlert'] === true && $transaction->type === 'expense') {
            // Check budget for the category
            $budget = \App\Models\Budget::where('user_id', $request->user()->id)
                ->where('category_id', $transaction->category_id)
                ->where('month', date('n'))
                ->where('year', date('Y'))
                ->first();

            if ($budget && $budget->limit_amount > 0) {
                // Get total expenses for this category in this month
                $totalSpent = \App\Models\Transaction::where('user_id', $request->user()->id)
                    ->where('category_id', $transaction->category_id)
                    ->where('type', 'expense')
                    ->whereYear('transaction_date', date('Y'))
                    ->whereMonth('transaction_date', date('m'))
                    ->sum('amount');
                
                $percent = ($totalSpent / $budget->limit_amount) * 100;
                
                $thresholdLevel = null;
                if ($percent >= 100) {
                    $thresholdLevel = 100;
                } elseif ($percent >= 80) {
                    $thresholdLevel = 80;
                }

                if ($thresholdLevel) {
                    // Cek apakah sudah pernah mengirim alert untuk threshold ini di bulan ini
                    $periodLabel = 'cat_' . $transaction->category_id . '_' . date('Y-m') . '_' . $thresholdLevel;
                    
                    $existingAlert = \App\Models\SpendingNotification::where('user_id', $request->user()->id)
                        ->where('type', 'budget_alert')
                        ->where('period_label', $periodLabel)
                        ->first();
                        
                    if (!$existingAlert) {
                        $isCritical = $thresholdLevel === 100;
                        $message = $isCritical
                            ? 'Peringatan Kritis: Pengeluaran ' . ($transaction->category->name ?? 'Kategori') . ' telah mencapai 100% dari limit bulan ini!'
                            : 'Peringatan: Pengeluaran ' . ($transaction->category->name ?? 'Kategori') . ' mencapai ' . round($percent) . '% dari limit bulan ini!';

                        \App\Models\SpendingNotification::create([
                            'user_id' => $request->user()->id,
                            'type' => 'budget_alert',
                            'period_type' => 'monthly',
                            'period_label' => $periodLabel,
                            'spent_percent' => $percent,
                            'message' => $message,
                            'is_read' => false,
                        ]);

                        // Kirim Email secara asynchronous (queue) jika queue terkonfigurasi, atau jalankan saat itu juga
                        Mail::to($request->user()->email)->send(
                            new BudgetAlertMail(
                                $request->user()->name,
                                $transaction->category->name ?? 'Tanpa Kategori',
                                $percent,
                                $totalSpent,
                                $budget->limit_amount
                            )
                        );
                    }
                }
            }
        }

        // Gamifikasi triggers
        try {
            $user = $request->user();
            $this->gamification->awardXP($user, 10, 'Mencatat Transaksi');
            $this->gamification->recordActivity($user);
            $this->gamification->recordQuestAction($user, 'record_transactions', 1);
            $this->gamification->updateAchievementProgress($user, 'first_tx', 1);

            $totalTxCount = Transaction::where('user_id', $user->id)->count();
            $this->gamification->updateAchievementProgress($user, 'tx_10', $totalTxCount);
            $this->gamification->updateAchievementProgress($user, 'tx_50', $totalTxCount);
            $this->gamification->updateAchievementProgress($user, 'tx_100', $totalTxCount);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gamification Error (Store Tx): ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Transaksi berhasil disimpan.',
            'data'    => new TransactionResource($transaction->load(['account', 'category'])),
        ], 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        return response()->json([
            'data' => new TransactionResource($transaction->load(['account', 'category'])),
        ]);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        $validated = $request->validate([
            'account_id'       => ['sometimes', 'exists:accounts,id'],
            'category_id'      => ['sometimes', 'exists:categories,id'],
            'goal_id'          => ['nullable', 'exists:goals,id'],
            'type'             => ['sometimes', 'in:income,expense'],
            'amount'           => ['sometimes', 'numeric', 'min:0.01'],
            'description'      => ['nullable', 'string', 'max:500'],
            'transaction_date' => ['sometimes', 'date'],
        ]);

        // Simpan data lama sebelum di-update
        $oldAccountId = $transaction->account_id;
        $oldType      = $transaction->type;
        $oldAmount    = (float) $transaction->amount;

        // Balikkan efek saldo lama
        $this->reverseAccountBalance($oldAccountId, $oldType, $oldAmount);

        $transaction->update($validated);
        $transaction->refresh();

        // Terapkan efek saldo baru (gunakan data terbaru setelah update)
        $this->updateAccountBalance($transaction->account_id, $transaction->type, (float) $transaction->amount);

        // Rekam ulang analisis spending
        $this->spending->recordNotification($request->user());

        return response()->json([
            'message' => 'Transaksi berhasil diperbarui.',
            'data'    => new TransactionResource($transaction->fresh()->load(['account', 'category'])),
        ]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        // Balikkan efek saldo
        $this->reverseAccountBalance($transaction->account_id, $transaction->type, $transaction->amount);

        $transaction->delete();

        // Rekam ulang analisis spending
        $this->spending->recordNotification($request->user());

        return response()->json(['message' => 'Transaksi berhasil dihapus.']);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function updateAccountBalance(int $accountId, string $type, float $amount): void
    {
        $account = Account::find($accountId);
        if (! $account) return;

        if ($type === 'income') {
            $account->increment('balance', $amount);
        } else {
            $account->decrement('balance', $amount);
        }
    }

    private function reverseAccountBalance(int $accountId, string $type, float $amount): void
    {
        // Reverse: income → kurangi, expense → tambah
        $this->updateAccountBalance($accountId, $type === 'income' ? 'expense' : 'income', $amount);
    }

    private function authorizeTransaction(Request $request, Transaction $transaction): void
    {
        abort_unless($transaction->user_id === $request->user()->id, 403, 'Akses ditolak.');
    }
}
