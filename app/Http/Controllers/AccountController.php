<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\BalanceAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = $request->user()
            ->accounts()
            ->withTrashed()
            ->orderBy('sort_order', 'asc')
            ->latest()
            ->get();

        return AccountResource::collection($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'type'           => ['required', 'in:cash,bank,ewallet'],
            'balance'        => ['nullable', 'numeric', 'min:0'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'account_holder' => ['nullable', 'string', 'max:255'],
            'color_theme'    => ['nullable', 'string', 'max:50'],
        ]);

        $account = $request->user()->accounts()->create($validated);

        return response()->json([
            'message' => 'Akun berhasil dibuat.',
            'data'    => new AccountResource($account),
        ], 201);
    }

    public function show(Request $request, Account $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        return response()->json(['data' => new AccountResource($account)]);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        $validated = $request->validate([
            'name'           => ['sometimes', 'required', 'string', 'max:255'],
            'type'           => ['sometimes', 'required', 'in:cash,bank,ewallet'],
            'balance'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'account_holder' => ['nullable', 'string', 'max:255'],
            'color_theme'    => ['nullable', 'string', 'max:50'],
        ]);

        $account->update($validated);

        return response()->json([
            'message' => 'Akun berhasil diperbarui.',
            'data'    => new AccountResource($account->fresh()),
        ]);
    }

    /**
     * Soft delete jika ada transaksi terkait, hard delete jika tidak ada.
     */
    public function destroy(Request $request, Account $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        if ($account->transactions()->exists()) {
            $account->delete(); // soft delete
            return response()->json(['message' => 'Akun diarsipkan (masih ada transaksi terkait).']);
        }

        $account->forceDelete();

        return response()->json(['message' => 'Akun berhasil dihapus.']);
    }

    /**
     * Memperbarui urutan akun secara massal
     */
    public function reorder(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'accounts' => ['required', 'array'],
            'accounts.*.id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'accounts.*.sort_order' => ['required', 'integer'],
        ]);

        foreach ($validated['accounts'] as $item) {
            Account::where('id', $item['id'])
                ->where('user_id', $request->user()->id)
                ->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['message' => 'Urutan akun berhasil diperbarui.']);
    }

    /**
     * P2: Reconcile/Adjust Balance — sesuaikan saldo manual
     */
    public function adjustBalance(Request $request, Account $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);

        $validated = $request->validate([
            'new_balance' => ['required', 'numeric', 'min:0'],
            'reason'      => ['nullable', 'string', 'max:255'],
        ]);

        $oldBalance        = (float) $account->balance;
        $newBalance        = (float) $validated['new_balance'];
        $adjustmentAmount  = $newBalance - $oldBalance;

        BalanceAdjustment::create([
            'user_id'           => $request->user()->id,
            'account_id'        => $account->id,
            'old_balance'       => $oldBalance,
            'new_balance'       => $newBalance,
            'adjustment_amount' => $adjustmentAmount,
            'reason'            => $validated['reason'] ?? null,
            'created_at'        => now(),
        ]);

        $account->update(['balance' => $newBalance]);

        return response()->json([
            'message'           => 'Saldo berhasil disesuaikan.',
            'data'              => new AccountResource($account->fresh()),
            'adjustment_amount' => $adjustmentAmount,
        ]);
    }

    private function authorizeAccount(Request $request, Account $account): void
    {
        abort_unless($account->user_id === $request->user()->id, 403, 'Akses ditolak.');
    }
}
