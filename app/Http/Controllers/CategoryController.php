<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * List: kategori default (user_id = null) + kategori custom milik user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = Category::withCount('transactions')
        ->where(function ($query) use ($request) {
            $query->whereNull('user_id')
                  ->orWhere('user_id', $request->user()->id);
        })
        ->when($request->type, fn ($q, $type) => $q->where('type', $type))
        ->orderByRaw('user_id IS NULL ASC') // default categories first
        ->orderBy('name')
        ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Buat kategori custom (user_id diset ke user yang sedang login).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'icon' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $category = $request->user()->categories()->create($validated);

        return response()->json([
            'message' => 'Kategori berhasil dibuat.',
            'data'    => new CategoryResource($category),
        ], 201);
    }

    /**
     * Update kategori custom saja — default tidak boleh diedit.
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizeCustom($request, $category);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'in:income,expense'],
            'icon' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Kategori berhasil diperbarui.',
            'data'    => new CategoryResource($category->fresh()),
        ]);
    }

    /**
     * Hapus kategori custom saja (soft delete jika ada transaksi terkait).
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeCustom($request, $category);

        if ($category->transactions()->exists()) {
            $category->delete(); // soft delete
            return response()->json(['message' => 'Kategori diarsipkan (masih ada transaksi terkait).']);
        }

        $category->forceDelete();

        return response()->json(['message' => 'Kategori berhasil dihapus.']);
    }

    private function authorizeCustom(Request $request, Category $category): void
    {
        if (is_null($category->user_id)) {
            abort(403, 'Kategori default tidak dapat diubah.');
        }
        abort_unless($category->user_id === $request->user()->id, 403, 'Akses ditolak.');
    }
}
