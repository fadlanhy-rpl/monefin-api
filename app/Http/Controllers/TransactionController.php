<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Jobs\ProcessTransactionSideEffects;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
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
                $search   = $request->search;
                $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(function ($sq) use ($search, $operator) {
                    $sq->where('description', $operator, "%{$search}%")
                       ->orWhereHas('category', fn ($c) => $c->where('name', $operator, "%{$search}%"));
                });
            })
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc');

        $perPage = $request->per_page ?? 20;

        return TransactionResource::collection($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'account_id'       => ['required', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'category_id'      => ['required', Rule::exists('categories', 'id')->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })],
            'goal_id'          => ['nullable', Rule::exists('goals', 'id')->where('user_id', $userId)],
            'type'             => ['required', 'in:income,expense'],
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'description'      => ['nullable', 'string', 'max:500'],
            'transaction_date' => ['required', 'date'],
        ]);

        $validated['user_id'] = $userId;

        $transaction = Transaction::create($validated);

        // Harus sync — saldo akun mempengaruhi tampilan yang langsung di-fetch setelah ini
        $this->updateAccountBalance($transaction->account_id, $transaction->type, $transaction->amount);

        // Semua side effects (gamifikasi, notifikasi, spending analysis, cache) berjalan di background
        ProcessTransactionSideEffects::dispatch(
            $request->user(),
            $transaction->load(['account', 'category']),
            'store',
            $request->user()->preferences ?? [],
        );

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

        $userId = $request->user()->id;

        $validated = $request->validate([
            'account_id'       => ['sometimes', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'category_id'      => ['sometimes', Rule::exists('categories', 'id')->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })],
            'goal_id'          => ['nullable', Rule::exists('goals', 'id')->where('user_id', $userId)],
            'type'             => ['sometimes', 'in:income,expense'],
            'amount'           => ['sometimes', 'numeric', 'min:0.01'],
            'description'      => ['nullable', 'string', 'max:500'],
            'transaction_date' => ['sometimes', 'date'],
        ]);

        $oldAccountId = $transaction->account_id;
        $oldType      = $transaction->type;
        $oldAmount    = $transaction->amount;

        $transaction->update($validated);

        $hasAccountChange = isset($validated['account_id']) && $validated['account_id'] !== $oldAccountId;
        $hasTypeChange    = isset($validated['type'])       && $validated['type']       !== $oldType;
        $hasAmountChange  = isset($validated['amount'])     && (float) $validated['amount'] !== (float) $oldAmount;

        if ($hasAccountChange || $hasTypeChange || $hasAmountChange) {
            $this->reverseAccountBalance($oldAccountId, $oldType, $oldAmount);
            $this->updateAccountBalance($transaction->account_id, $transaction->type, $transaction->amount);
        }

        ProcessTransactionSideEffects::dispatch(
            $request->user(),
            $transaction->fresh()->load(['account', 'category']),
            'update',
            $request->user()->preferences ?? [],
        );

        return response()->json([
            'message' => 'Transaksi berhasil diperbarui.',
            'data'    => new TransactionResource($transaction->fresh()->load(['account', 'category'])),
        ]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        // Harus sync — saldo harus langsung ter-update sebelum record dihapus
        $this->reverseAccountBalance($transaction->account_id, $transaction->type, $transaction->amount);

        $transaction->delete();

        ProcessTransactionSideEffects::dispatch(
            $request->user(),
            $transaction,
            'delete',
            $request->user()->preferences ?? [],
        );

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
        $this->updateAccountBalance($accountId, $type === 'income' ? 'expense' : 'income', $amount);
    }

    private function authorizeTransaction(Request $request, Transaction $transaction): void
    {
        abort_unless($transaction->user_id === $request->user()->id, 403, 'Akses ditolak.');
    }
}
