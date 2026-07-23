<?php

namespace App\Http\Controllers;

use App\Http\Resources\IncomeSettingResource;
use App\Models\IncomeSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $setting = $request->user()->activeIncomeSetting()->first();

        if (! $setting) {
            return response()->json(['data' => null, 'message' => 'Uang saku belum diatur.'], 200);
        }

        return response()->json(['data' => new IncomeSettingResource($setting)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'period_type'    => ['required', 'in:weekly,monthly'],
            'effective_date' => ['nullable', 'date'],
        ]);

        // Nonaktifkan setting sebelumnya
        IncomeSetting::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $setting = $request->user()->incomeSettings()->create([
            'amount'         => $validated['amount'],
            'period_type'    => $validated['period_type'],
            'is_active'      => true,
            'effective_date' => $validated['effective_date'] ?? today(),
        ]);

        return response()->json([
            'message' => 'Uang saku berhasil disimpan.',
            'data'    => new IncomeSettingResource($setting),
        ], 201);
    }
}
