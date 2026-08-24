<?php

namespace App\Http\Controllers;

use App\Http\Resources\GoalResource;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class GoalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $goals = $request->user()->goals()->orderByDesc('is_pinned')->latest()->get();

        return GoalResource::collection($goals);
    }

    public function deposit(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeGoal($request, $goal);

        $validated = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'amount'     => ['required', 'numeric', 'min:0.01'],
        ]);

        $account = $request->user()->accounts()->findOrFail($validated['account_id']);
        $amount = (float) $validated['amount'];
        $target = (float) $goal->target_amount;
        $current = (float) $goal->current_amount;
        $remaining = max(0, $target - $current);

        if ($remaining <= 0) {
            return response()->json([
                'message' => 'Target tabungan ini sudah tercapai sepenuhnya (100%). Tidak perlu menambah setoran lagi!'
            ], 422);
        }

        $wasCapped = false;
        $actualDeposit = $amount;

        if ($amount > $remaining) {
            $wasCapped = true;
            $actualDeposit = $remaining;
        }

        if ((float) $account->balance < $actualDeposit) {
            return response()->json([
                'message' => 'Saldo akun tidak mencukupi untuk melakukan deposit tabungan.'
            ], 422);
        }

        $category = $request->user()->categories()->where('type', 'expense')->first();
        $categoryId = $category?->id;

        DB::transaction(function () use ($request, $account, $goal, $actualDeposit, $categoryId) {
            $account->decrement('balance', $actualDeposit);
            $goal->increment('current_amount', $actualDeposit);

            $request->user()->transactions()->create([
                'account_id'       => $account->id,
                'category_id'      => $categoryId,
                'goal_id'          => $goal->id,
                'type'             => 'expense',
                'amount'           => $actualDeposit,
                'description'      => "Setor Tabungan: {$goal->name}",
                'transaction_date' => now()->toDateString(),
            ]);
        });

        if ($wasCapped) {
            $message = "Setoran disesuaikan karena target tabungan '{$goal->name}' telah tercapai 100%! 🎉";
        } else {
            $message = "Berhasil menyetor ke target tabungan '{$goal->name}'!";
        }

        return response()->json([
            'message'    => $message,
            'was_capped' => $wasCapped,
            'data'       => new GoalResource($goal->fresh()),
        ]);
    }

    public function withdraw(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeGoal($request, $goal);

        $validated = $request->validate([
            'account_id' => ['required', 'exists:accounts,id'],
            'amount'     => ['required', 'numeric', 'min:0.01'],
        ]);

        $account = $request->user()->accounts()->findOrFail($validated['account_id']);
        $amount = (float) $validated['amount'];

        if ((float) $goal->current_amount < $amount) {
            return response()->json([
                'message' => 'Saldo tabungan goal tidak mencukupi untuk ditarik.'
            ], 422);
        }

        $category = $request->user()->categories()->where('type', 'income')->first();
        $categoryId = $category?->id;

        DB::transaction(function () use ($request, $account, $goal, $amount, $categoryId) {
            $goal->decrement('current_amount', $amount);
            $account->increment('balance', $amount);

            $request->user()->transactions()->create([
                'account_id'       => $account->id,
                'category_id'      => $categoryId,
                'goal_id'          => $goal->id,
                'type'             => 'income',
                'amount'           => $amount,
                'description'      => "Penarikan Tabungan: {$goal->name}",
                'transaction_date' => now()->toDateString(),
            ]);
        });

        return response()->json([
            'message' => 'Berhasil menarik dana dari target tabungan ke akun Anda!',
            'data'    => new GoalResource($goal->fresh()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string', 'max:500'],
            'color'          => ['nullable', 'string', 'max:20'],
            'icon'           => ['nullable', 'string', 'max:50'],
            'layout_type'    => ['nullable', 'string', 'max:20'],
            'is_pinned'      => ['nullable', 'boolean'],
            'target_amount'  => ['required', 'numeric', 'min:0.01'],
            'current_amount' => ['nullable', 'numeric', 'min:0'],
            'deadline'       => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $goal = $request->user()->goals()->create($validated);

        return response()->json([
            'message' => 'Goal berhasil dibuat.',
            'data'    => new GoalResource($goal),
        ], 201);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeGoal($request, $goal);

        return response()->json(['data' => new GoalResource($goal)]);
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeGoal($request, $goal);

        $validated = $request->validate([
            'name'           => ['sometimes', 'required', 'string', 'max:255'],
            'description'    => ['nullable', 'string', 'max:500'],
            'color'          => ['nullable', 'string', 'max:20'],
            'icon'           => ['nullable', 'string', 'max:50'],
            'layout_type'    => ['nullable', 'string', 'max:20'],
            'is_pinned'      => ['nullable', 'boolean'],
            'target_amount'  => ['sometimes', 'numeric', 'min:0.01'],
            'current_amount' => ['sometimes', 'numeric', 'min:0'],
            'deadline'       => ['nullable', 'date'],
        ]);

        $goal->update($validated);

        return response()->json([
            'message' => 'Goal berhasil diperbarui.',
            'data'    => new GoalResource($goal->fresh()),
        ]);
    }

    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeGoal($request, $goal);
        $goal->delete();

        return response()->json(['message' => 'Goal berhasil dihapus.']);
    }

    private function authorizeGoal(Request $request, Goal $goal): void
    {
        abort_unless($goal->user_id === $request->user()->id, 403, 'Akses ditolak.');
    }
}
