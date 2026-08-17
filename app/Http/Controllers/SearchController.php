<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * GET /api/search?q={keyword}
     *
     * Global search untuk user MoneFin.
     * Mencari di: Transactions, Categories, Accounts, Goals
     * Max 5 hasil per entitas.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = trim($request->input('q', ''));

        // Guard: return kosong jika query tidak ada
        if ($query === '') {
            return response()->json([
                'success' => true,
                'data'    => [
                    'transactions' => [],
                    'categories'   => [],
                    'accounts'     => [],
                    'goals'        => [],
                ],
            ]);
        }

        $like = "%{$query}%";

        // 1. Transactions — cari di deskripsi atau nama kategori
        $transactions = Transaction::where('user_id', $user->id)
            ->where(function ($q) use ($like) {
                $q->where('description', 'ilike', $like)
                  ->orWhereHas('category', fn($c) => $c->where('name', 'ilike', $like));
            })
            ->with('category:id,name,icon')
            ->select('id', 'description', 'amount', 'type', 'transaction_date', 'category_id')
            ->orderByDesc('transaction_date')
            ->limit(5)
            ->get()
            ->map(fn($t) => [
                'id'               => $t->id,
                'description'      => $t->description ?: ($t->category?->name ?? 'Transaksi'),
                'amount'           => (float) $t->amount,
                'type'             => $t->type,
                'transaction_date' => $t->transaction_date,
                'category'         => $t->category?->name,
                'category_icon'    => $t->category?->icon,
            ]);

        // 2. Categories — cari di nama kategori milik user / default
        $categories = Category::where('name', 'ilike', $like)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->select('id', 'name', 'icon', 'type')
            ->limit(5)
            ->get();

        // 3. Accounts — cari nama akun milik user
        $accounts = Account::where('user_id', $user->id)
            ->where('name', 'ilike', $like)
            ->select('id', 'name', 'balance', 'type')
            ->limit(5)
            ->get();

        // 4. Goals — cari nama/deskripsi goal milik user
        $goals = Goal::where('user_id', $user->id)
            ->where(function ($q) use ($like) {
                $q->where('name', 'ilike', $like)
                  ->orWhere('description', 'ilike', $like);
            })
            ->select('id', 'name', 'target_amount', 'current_amount', 'deadline')
            ->limit(5)
            ->get()
            ->map(fn($g) => [
                'id'             => $g->id,
                'title'          => $g->name,
                'target_amount'  => (float) $g->target_amount,
                'current_amount' => (float) $g->current_amount,
                'deadline'       => $g->deadline,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'transactions' => $transactions,
                'categories'   => $categories,
                'accounts'     => $accounts,
                'goals'        => $goals,
            ],
        ]);
    }
}
