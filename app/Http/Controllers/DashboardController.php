<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\SpendingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private SpendingAnalysisService $spending) {}

    /**
     * GET /api/dashboard/summary
     * Ringkasan untuk halaman dashboard:
     * - Total saldo semua akun aktif
     * - Total income & expense bulan berjalan
     * - Status hemat/normal/boros
     * - Pengeluaran per kategori (pie chart)
     * - Transaksi terbaru
     */
    public function summary(Request $request): JsonResponse
    {
        $user  = $request->user();
        $month = now()->month;
        $year  = now()->year;

        // 1. Total saldo semua akun aktif (tidak soft-deleted)
        $totalBalance = $user->accounts()->sum('balance');

        // 2. Total income & expense bulan ini
        $totalIncomeThisMonth = Transaction::where('user_id', $user->id)
            ->where('type', 'income')
            ->whereMonth('transaction_date', $month)
            ->whereYear('transaction_date', $year)
            ->sum('amount');

        $totalExpenseThisMonth = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereMonth('transaction_date', $month)
            ->whereYear('transaction_date', $year)
            ->sum('amount');

        // 3. Status hemat/normal/boros
        $spendingStatus = $this->spending->analyze($user);

        // 4. Pengeluaran per kategori bulan ini (untuk pie chart)
        $expenseByCategory = Transaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereMonth('transaction_date', $month)
            ->whereYear('transaction_date', $year)
            ->with('category:id,name,icon')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->get()
            ->map(fn ($row) => [
                'category'    => $row->category?->name ?? 'Lain-lain',
                'icon'        => $row->category?->icon,
                'amount'      => (float) $row->total,
                'category_id' => $row->category_id,
            ])
            ->sortByDesc('amount')
            ->values();

        // 5. Transaksi terbaru (5 terakhir)
        $recentTransactions = Transaction::where('user_id', $user->id)
            ->with(['account:id,name,type', 'category:id,name,icon,type'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'total_balance'            => (float) $totalBalance,
                'total_income_this_month'  => (float) $totalIncomeThisMonth,
                'total_expense_this_month' => (float) $totalExpenseThisMonth,
                'savings_this_month'       => (float) ($totalIncomeThisMonth - $totalExpenseThisMonth),
                'spending_status'          => $spendingStatus,
                'expense_by_category'      => $expenseByCategory,
                'recent_transactions'      => $recentTransactions,
            ],
        ]);
    }
}
