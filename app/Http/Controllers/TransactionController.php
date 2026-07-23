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

        // Rekam analisis spending
        $this->spending->recordNotification($request->user());

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

        // Balikkan efek saldo lama
        $this->reverseAccountBalance($transaction->account_id, $transaction->type, $transaction->amount);

        $transaction->update($validated);

        // Terapkan efek saldo baru
        $this->updateAccountBalance($transaction->account_id, $transaction->type, $transaction->amount);

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
