<?php

namespace App\Http\Controllers;

use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Models\Transaction;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BudgetController extends Controller
{
    public function __construct(
        private GamificationService $gamification
    ) {}
    public function index(Request $request): AnonymousResourceCollection
    {
        $month = $request->month ?? now()->month;
        $year  = $request->year  ?? now()->year;

        $budgets = Budget::where('user_id', $request->user()->id)
            ->where('month', $month)
            ->where('year', $year)
            ->with('category')
            ->get()
            ->map(function (Budget $budget) {
                // Hitung total pengeluaran di kategori & bulan ini
                $spent = Transaction::where('user_id', $budget->user_id)
                    ->where('category_id', $budget->category_id)
                    ->where('type', 'expense')
                    ->whereYear('transaction_date', $budget->year)
                    ->whereMonth('transaction_date', $budget->month)
                    ->sum('amount');

                $budget->spent_amount = $spent;

                return $budget;
            });

        return BudgetResource::collection($budgets);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id'  => ['required', 'exists:categories,id'],
            'month'        => ['required', 'integer', 'min:1', 'max:12'],
            'year'         => ['required', 'integer', 'min:2000'],
            'limit_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $validated['user_id'] = $request->user()->id;

        $budget = Budget::updateOrCreate(
            [
                'user_id'     => $validated['user_id'],
                'category_id' => $validated['category_id'],
                'month'       => $validated['month'],
                'year'        => $validated['year'],
            ],
            ['limit_amount' => $validated['limit_amount']]
        );

        try {
            $user = $request->user();
            $this->gamification->awardXP($user, 25, 'Membuat/Memperbarui Anggaran');
            $this->gamification->recordActivity($user);
            $budgetCount = Budget::where('user_id', $user->id)->count();
            $this->gamification->updateAchievementProgress($user, 'budget_created', $budgetCount);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gamification Error (Store Budget): ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Budget berhasil disimpan.',
            'data'    => new BudgetResource($budget->load('category')),
        ], 201);
    }

    public function show(Request $request, Budget $budget): JsonResponse
    {
        $this->authorizeBudget($request, $budget);

        return response()->json(['data' => new BudgetResource($budget->load('category'))]);
    }

    public function update(Request $request, Budget $budget): JsonResponse
    {
        $this->authorizeBudget($request, $budget);

        $validated = $request->validate([
            'limit_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $budget->update($validated);

        return response()->json([
            'message' => 'Budget berhasil diperbarui.',
            'data'    => new BudgetResource($budget->fresh()->load('category')),
        ]);
    }

    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        $this->authorizeBudget($request, $budget);
        $budget->delete();

        return response()->json(['message' => 'Budget berhasil dihapus.']);
    }

    private function authorizeBudget(Request $request, Budget $budget): void
    {
        abort_unless($budget->user_id === $request->user()->id, 403, 'Akses ditolak.');
    }
}
