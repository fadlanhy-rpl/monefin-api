<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Budget;
use App\Services\SpendingAnalysisService;
use Illuminate\Support\Facades\DB;

class TrashController extends Controller
{
    protected $spending;

    public function __construct(SpendingAnalysisService $spending)
    {

        
        $this->spending = $spending;
    }

    /**
     * Get all soft-deleted items for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $accounts = Account::onlyTrashed()->where('user_id', $userId)->get();
        $transactions = Transaction::onlyTrashed()->where('user_id', $userId)->with(['account', 'category'])->get();
        $categories = Category::onlyTrashed()->where('user_id', $userId)->get();
        $goals = Goal::onlyTrashed()->where('user_id', $userId)->get();
        $budgets = Budget::onlyTrashed()->where('user_id', $userId)->with('category')->get();

        return response()->json([
            'data' => [
                'accounts' => $accounts,
                'transactions' => $transactions,
                'categories' => $categories,
                'goals' => $goals,
                'budgets' => $budgets,
            ],
            'message' => 'Data trash berhasil diambil.'
        ]);
    }

    /**
     * Restore a soft-deleted item.
     */
    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        $userId = $request->user()->id;
        $model = $this->getModelInstance($type, $id, $userId);

        if (!$model) {
            return response()->json(['message' => 'Data tidak ditemukan di Trashbin.'], 404);
        }

        DB::beginTransaction();
        try {
            // Claim the restore atomically: only one concurrent caller may move a
            // given row from trashed back to live, and only that caller re-applies
            // the balance effect below.
            $claimed = $model->newQuery()->getQuery()
                ->from((new $model)->getTable())
                ->whereKey($model->getKey())
                ->whereNotNull('deleted_at')
                ->update(['deleted_at' => null]);

            if ($claimed !== 1) {
                DB::rollBack();
                return response()->json([
                    'message' => ucfirst($type) . ' berhasil dipulihkan.'
                ]);
            }

            // If it's a transaction, we need to re-apply the balance to the account
            if ($type === 'transaction') {
                $account = Account::find($model->account_id);
                // Even if account is soft-deleted, find() will not return it. 
                // But if the account is restored later or already restored, we should update its balance if it exists.
                // It's better to fetch even trashed accounts to keep the balance accurate.
                if (!$account) {
                    $account = Account::withTrashed()->find($model->account_id);
                }
                
                if ($account) {
                    if ($model->type === 'income') {
                        $account->increment('balance', $model->amount);
                    } else if ($model->type === 'expense') {
                        $account->decrement('balance', $model->amount);
                    }
                }
                
                $this->spending->recordNotification($request->user());
            }

            DB::commit();

            return response()->json([
                'message' => ucfirst($type) . ' berhasil dipulihkan.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal memulihkan data: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Permanently delete a soft-deleted item.
     */
    public function forceDelete(Request $request, string $type, int $id): JsonResponse
    {
        $userId = $request->user()->id;
        $model = $this->getModelInstance($type, $id, $userId);

        if (!$model) {
            return response()->json(['message' => 'Data tidak ditemukan di Trashbin.'], 404);
        }

        try {
            $model->forceDelete();

            return response()->json([
                'message' => ucfirst($type) . ' berhasil dihapus permanen.'
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menghapus data secara permanen: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Helper to get the correct model instance based on type.
     */
    private function getModelInstance(string $type, int $id, int $userId)
    {
        switch ($type) {
            case 'account':
                return Account::onlyTrashed()->where('user_id', $userId)->where('id', $id)->first();
            case 'transaction':
                return Transaction::onlyTrashed()->where('user_id', $userId)->where('id', $id)->first();
            case 'category':
                return Category::onlyTrashed()->where('user_id', $userId)->where('id', $id)->first();
            case 'goal':
                return Goal::onlyTrashed()->where('user_id', $userId)->where('id', $id)->first();
            case 'budget':
                return Budget::onlyTrashed()->where('user_id', $userId)->where('id', $id)->first();
            default:
                return null;
        }
    }
}
