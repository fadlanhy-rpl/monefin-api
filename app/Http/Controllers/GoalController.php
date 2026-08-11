<?php

namespace App\Http\Controllers;

use App\Http\Resources\GoalResource;
use App\Models\Goal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GoalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $goals = $request->user()->goals()->orderByDesc('is_pinned')->latest()->get();

        return GoalResource::collection($goals);
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
