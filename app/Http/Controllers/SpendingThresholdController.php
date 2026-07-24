<?php

namespace App\Http\Controllers;

use App\Http\Resources\SpendingThresholdResource;
use App\Models\SpendingThreshold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpendingThresholdController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $threshold = $request->user()->spendingThreshold
            ?? new SpendingThreshold([
                'hemat_max_percent' => 60.00,
                'boros_min_percent' => 85.00,
            ]);

        return response()->json(['data' => new SpendingThresholdResource($threshold)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hemat_max_percent' => ['required', 'numeric', 'min:1', 'max:100'],
            'boros_min_percent' => ['required', 'numeric', 'min:1', 'max:100'],
        ]);

        abort_if(
            $validated['hemat_max_percent'] >= $validated['boros_min_percent'],
            422,
            'Persentase hemat harus lebih kecil dari persentase boros.'
        );

        $threshold = SpendingThreshold::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated
        );

        return response()->json([
            'message' => 'Threshold berhasil disimpan.',
            'data'    => new SpendingThresholdResource($threshold),
        ]);
    }
}
