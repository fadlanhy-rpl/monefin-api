<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\SpendingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function __construct(private SpendingAnalysisService $spending) {}

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
            ->when($request->type,        fn ($q) => $q->where('type', $request->type))
            ->when($request->search, fn ($q) => $q->where('description', 'like', "%{$request->search}%"))
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
                'message' => 'Transaksi baru: ' . ($transaction->type === 'income' ? '+' : '-') . 'Rp ' . number_format($transaction->amount, 0, ',', '.') . ' (' . ($transaction->category->name ?? 'Tanpa Kategori') . ')',
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
                
                if ($percent >= 80) {
                    // Check if already sent a budget alert for this category this month
                    $existingAlert = \App\Models\SpendingNotification::where('user_id', $request->user()->id)
                        ->where('type', 'budget_alert')
                        ->where('period_label', 'cat_' . $transaction->category_id . '_' . date('Y-m'))
                        ->first();
                        
                    if (!$existingAlert) {
                        \App\Models\SpendingNotification::create([
                            'user_id' => $request->user()->id,
                            'type' => 'budget_alert',
                            'period_type' => 'monthly',
                            'period_label' => 'cat_' . $transaction->category_id . '_' . date('Y-m'),
                            'spent_percent' => $percent,
                            'message' => 'Peringatan: Pengeluaran ' . ($transaction->category->name ?? 'Kategori') . ' mencapai ' . round($percent) . '% dari limit bulan ini!',
                            'is_read' => false,
                        ]);
                    }
                }
            }
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
