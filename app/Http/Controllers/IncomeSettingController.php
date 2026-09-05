<?php

namespace App\Http\Controllers;

use App\Http\Resources\IncomeSettingResource;
use App\Models\IncomeSetting;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncomeSettingController extends Controller
{
    public function __construct(
        private GamificationService $gamification
    ) {}
    /**
     * Get all active recurring transactions for the user.
     */
    public function index(Request $request): JsonResponse
    {
        $settings = $request->user()->incomeSettings()
            ->with(['account', 'category'])
            ->where('is_active', true)
            ->get();

        return response()->json(['data' => IncomeSettingResource::collection($settings)]);
    }

    /**
     * Store a new recurring transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'type'           => ['required', 'in:income,expense'],
            'title'          => ['required', 'string', 'max:255'],
            'account_id'     => [
                'nullable',
                Rule::exists('accounts', 'id')->where('user_id', $userId),
            ],
            'category_id'    => [
                'nullable',
                Rule::exists('categories', 'id')->where(function ($query) use ($userId) {
                    $query->where('user_id', $userId)->orWhereNull('user_id');
                }),
            ],
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'period_type'    => ['required', 'in:weekly,monthly,daily'],
            'effective_date' => ['nullable', 'date'],
        ]);

        $setting = $request->user()->incomeSettings()->create([
            'type'           => $validated['type'],
            'title'          => $validated['title'],
            'account_id'     => $validated['account_id'] ?? null,
            'category_id'    => $validated['category_id'] ?? null,
            'amount'         => $validated['amount'],
            'period_type'    => $validated['period_type'],
            'is_active'      => true,
            'effective_date' => $validated['effective_date'] ?? today(),
        ]);

        try {
            $user = $request->user();
            $this->gamification->awardXP($user, 50, 'Setup Transaksi Rutin');
            $this->gamification->recordActivity($user);
            $this->gamification->updateAchievementProgress($user, 'recurring_setup', 1);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gamification Error (Store Recurring): ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Transaksi rutin berhasil ditambahkan.',
            'data'    => new IncomeSettingResource($setting->load(['account', 'category'])),
        ], 201);
    }

    /**
     * Update an existing recurring transaction.
     */
    public function update(Request $request, IncomeSetting $incomeSetting): JsonResponse
    {
        if ($incomeSetting->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $userId = $request->user()->id;

        $validated = $request->validate([
            'type'           => ['sometimes', 'in:income,expense'],
            'title'          => ['sometimes', 'string', 'max:255'],
            'account_id'     => [
                'nullable',
                Rule::exists('accounts', 'id')->where('user_id', $userId),
            ],
            'category_id'    => [
                'nullable',
                Rule::exists('categories', 'id')->where(function ($query) use ($userId) {
                    $query->where('user_id', $userId)->orWhereNull('user_id');
                }),
            ],
            'amount'         => ['sometimes', 'numeric', 'min:0.01'],
            'period_type'    => ['sometimes', 'in:weekly,monthly,daily'],
            'effective_date' => ['nullable', 'date'],
            'is_active'      => ['sometimes', 'boolean'],
        ]);

        $incomeSetting->update($validated);

        return response()->json([
            'message' => 'Transaksi rutin berhasil diperbarui.',
            'data'    => new IncomeSettingResource($incomeSetting->fresh(['account', 'category'])),
        ]);
    }

    /**
     * Delete (deactivate) a recurring transaction.
     */
    public function destroy(Request $request, IncomeSetting $incomeSetting): JsonResponse
    {
        if ($incomeSetting->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $incomeSetting->update(['is_active' => false]);
        $incomeSetting->delete();

        return response()->json([
            'message' => 'Transaksi rutin berhasil dihapus.',
        ]);
    }
}
