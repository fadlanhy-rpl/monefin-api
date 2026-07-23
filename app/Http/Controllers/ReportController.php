<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * GET /api/reports/compare
     * Perbandingan income, expense, savings antar bulan.
     *
     * Query params:
     *   - months: jumlah bulan ke belakang (default 6)
     *   - start_month: YYYY-MM (opsional, untuk custom range)
     *   - end_month: YYYY-MM (opsional, untuk custom range)
     */
    public function compare(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->start_month && $request->end_month) {
            // Custom range
            [$startYear, $startMonth] = explode('-', $request->start_month);
            [$endYear, $endMonth]     = explode('-', $request->end_month);

            $start = \Carbon\Carbon::create((int) $startYear, (int) $startMonth, 1)->startOfMonth();
            $end   = \Carbon\Carbon::create((int) $endYear, (int) $endMonth, 1)->endOfMonth();
        } else {
            // Default: N bulan terakhir
            $months = (int) ($request->months ?? 6);
            $end    = now()->endOfMonth();
            $start  = now()->subMonths($months - 1)->startOfMonth();
        }

        // Query agregasi per bulan — kompatibel SQLite (dev) & PostgreSQL (prod)
        $driver = \Illuminate\Support\Facades\DB::getDriverName();

        if ($driver === 'sqlite') {
            $monthExpr = "strftime('%Y-%m', transaction_date)";
        } else {
            $monthExpr = "TO_CHAR(transaction_date, 'YYYY-MM')";
        }

        $rows = Transaction::where('user_id', $user->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("{$monthExpr} AS month, type, SUM(amount) AS total")
            ->groupByRaw("{$monthExpr}, type")
            ->orderByRaw("{$monthExpr} ASC")
            ->get();

        // Transformasi ke format { month, income, expense, savings }
        $data = [];

        foreach ($rows as $row) {
            $data[$row->month] ??= ['month' => $row->month, 'income' => 0, 'expense' => 0, 'savings' => 0];
            $data[$row->month][$row->type] += (float) $row->total;
        }

        // Isi bulan yang kosong (tidak ada transaksi) agar grafik tidak putus
        $current = $start->copy();
        while ($current <= $end) {
            $key = $current->format('Y-m');
            $data[$key] ??= ['month' => $key, 'income' => 0, 'expense' => 0, 'savings' => 0];
            $current->addMonth();
        }

        // Hitung savings & urutkan
        $result = collect($data)
            ->map(function ($row) {
                $row['savings'] = $row['income'] - $row['expense'];
                return $row;
            })
            ->sortBy('month')
            ->values();

        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/reports/export
     * Export transaksi ke CSV (P2).
     *
     * Query params: start_date, end_date, type, category_id, account_id
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = $request->user();

        $transactions = Transaction::where('user_id', $user->id)
            ->with(['account', 'category'])
            ->when($request->start_date, fn ($q) => $q->where('transaction_date', '>=', $request->start_date))
            ->when($request->end_date,   fn ($q) => $q->where('transaction_date', '<=', $request->end_date))
            ->when($request->type,        fn ($q) => $q->where('type', $request->type))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->account_id,  fn ($q) => $q->where('account_id', $request->account_id))
            ->orderBy('transaction_date', 'desc')
            ->get();

        $filename = 'monefin-transactions-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($transactions) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // Header row
            fputcsv($handle, ['Tanggal', 'Tipe', 'Kategori', 'Akun', 'Jumlah', 'Deskripsi']);

            foreach ($transactions as $t) {
                fputcsv($handle, [
                    $t->transaction_date,
                    $t->type,
                    $t->category?->name ?? '-',
                    $t->account?->name ?? '-',
                    $t->amount,
                    $t->description ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
