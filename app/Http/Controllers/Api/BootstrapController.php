<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    /**
     * GET /api/bootstrap
     *
     * Satu boot Laravel untuk 3 kebutuhan awal setiap halaman:
     * user (me) + accounts + categories. Menghemat 2-3 boot dibanding
     * 3 endpoint terpisah di shared hosting yang TTFB-nya 3-6 detik.
     *
     * Bentuk respons disamakan dengan endpoint individual agar frontend
     * bisa mem-prime cache-nya tanpa request tambahan.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = AccountResource::collection(
            $user->accounts()->orderBy('sort_order', 'asc')->latest()->get()
        )->resolve($request);

        $categories = CategoryResource::collection(
            Category::withCount(['transactions' => fn ($q) => $q->where('user_id', $user->id)])
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))
                ->orderByRaw('user_id IS NULL ASC')
                ->orderBy('name')
                ->get()
        )->resolve($request);

        return response()->json([
            'data' => [
                'user'       => $user,
                'accounts'   => array_values($accounts),
                'categories' => array_values($categories),
            ],
        ]);
    }
}
