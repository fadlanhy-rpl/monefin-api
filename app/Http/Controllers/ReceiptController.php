<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Jobs\ProcessTransactionSideEffects;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\AiReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReceiptController extends Controller
{
    public function __construct(
        private readonly AiReceiptService $receiptService,
    ) {}

    /**
     * POST /api/receipts/scan
     * Upload receipt image and extract structured data using user's BYOK Vision LLM.
     */
    public function scan(Request $request): JsonResponse
    {
        @set_time_limit(120);

        $request->validate([
            'image'        => ['required_without:image_base64', 'nullable', 'file', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            'image_base64' => ['required_without:image', 'nullable', 'string'],
            'mime_type'    => ['nullable', 'string', 'in:image/jpeg,image/png,image/webp,image/jpg'],
        ], [
            'image.mimes' => 'Format gambar harus JPG, PNG, atau WebP.',
            'image.max'   => 'Ukuran gambar maksimal adalah 10MB.',
        ]);

        $mimeType = 'image/jpeg';
        $base64   = '';

        if ($request->hasFile('image')) {
            $file     = $request->file('image');
            $mimeType = $file->getMimeType() ?: 'image/jpeg';
            $base64   = base64_encode(file_get_contents($file->getRealPath()));
        } elseif ($request->filled('image_base64')) {
            $raw = $request->input('image_base64');
            // Strip data:image/...;base64, prefix if present
            if (preg_match('/^data:(image\/[a-zA-Z0-9]+);base64,(.+)$/', $raw, $matches)) {
                $mimeType = $matches[1];
                $base64   = $matches[2];
            } else {
                $base64   = $raw;
                $mimeType = $request->input('mime_type', 'image/jpeg');
            }
        }

        $result = $this->receiptService->scanReceipt($request->user(), $base64, $mimeType);

        if (!$result['success']) {
            $status = match ($result['code'] ?? '') {
                'NO_AI_KEY'            => 422,
                'QUOTA_EXCEEDED'       => 402,
                'VISION_NOT_SUPPORTED' => 422,
                default                => 500,
            };

            return response()->json([
                'error'   => true,
                'code'    => $result['code'] ?? 'SCAN_ERROR',
                'message' => $result['message'],
                'raw'     => $result['raw'] ?? null,
            ], $status);
        }

        return response()->json([
            'success' => true,
            'data'    => $result['data'],
        ]);
    }

    /**
     * POST /api/receipts/confirm
     * Confirm receipt review and create transaction(s).
     */
    public function confirm(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Normalize multipart/form-data boolean and JSON array inputs
        if ($request->has('save_receipt_image')) {
            $request->merge([
                'save_receipt_image' => filter_var($request->input('save_receipt_image'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            ]);
        }
        if ($request->has('split_by_category')) {
            $request->merge([
                'split_by_category' => filter_var($request->input('split_by_category'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            ]);
        }
        if (is_string($request->input('items'))) {
            $decoded = json_decode($request->input('items'), true);
            if (is_array($decoded)) {
                $request->merge(['items' => $decoded]);
            }
        }

        $validated = $request->validate([
            'account_id'          => ['required', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'category_id'         => ['required', Rule::exists('categories', 'id')->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })],
            'type'                => ['required', 'in:income,expense'],
            'amount'              => ['required', 'numeric', 'min:0.01'],
            'description'         => ['required', 'string', 'max:500'],
            'transaction_date'    => ['required', 'date'],
            'save_receipt_image'  => ['nullable', 'boolean'],
            'receipt_image'       => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            'save_mode'           => ['nullable', 'in:summary,itemized'],
            'merchant'            => ['nullable', 'string', 'max:255'],
            'subtotal'            => ['nullable', 'numeric'],
            'tax'                 => ['nullable', 'numeric'],
            'discount'            => ['nullable', 'numeric'],
            'items'               => ['nullable', 'array'],
            'items.*.name'        => ['required_with:items', 'string', 'max:255'],
            'items.*.qty'         => ['nullable', 'numeric'],
            'items.*.price'       => ['nullable', 'numeric'],
            'items.*.total'       => ['nullable', 'numeric'],
            'items.*.category_id' => ['nullable', 'integer'],
            'split_by_category'   => ['nullable', 'boolean'],
        ]);

        $saveReceiptImage = filter_var($request->input('save_receipt_image', false), FILTER_VALIDATE_BOOLEAN);
        $receiptImagePath = null;

        // 1. Handle receipt image storage
        if ($saveReceiptImage && $request->hasFile('receipt_image')) {
            $file = $request->file('receipt_image');
            $ext  = $file->getClientOriginalExtension() ?: 'jpg';
            $filename = 'rcpt_' . $userId . '_' . time() . '_' . Str::random(12) . '.' . $ext;

            // Store in public/uploads/receipts/{userId}/ via uploads disk (no symlink required on shared hosting)
            $file->storeAs("receipts/{$userId}", $filename, 'uploads');
            $receiptImagePath = "uploads/receipts/{$userId}/{$filename}";
        }

        $saveMode        = $validated['save_mode'] ?? 'summary';
        $splitByCategory = filter_var($request->input('split_by_category', false), FILTER_VALIDATE_BOOLEAN);
        $items           = $validated['items'] ?? [];

        // 2. Build receipt_data JSON payload
        $receiptData = [
            'merchant'           => $validated['merchant'] ?? $validated['description'],
            'subtotal'           => (float) ($validated['subtotal'] ?? $validated['amount']),
            'tax'                => (float) ($validated['tax'] ?? 0),
            'discount'           => (float) ($validated['discount'] ?? 0),
            'total'              => (float) $validated['amount'],
            'save_mode'          => $saveMode,
            'items_count'        => count($items),
            'items'              => $items,
            'saved_image'        => (bool) $receiptImagePath,
        ];

        // 3. Option: Split into multiple transactions if split_by_category is true AND multiple distinct categories exist
        if ($splitByCategory && !empty($items) && count($items) > 1) {
            $createdTransactions = DB::transaction(function () use ($userId, $validated, $items, $receiptImagePath, $receiptData) {
                // Group items by category_id
                $grouped = collect($items)->groupBy(function ($item) use ($validated) {
                    return $item['category_id'] ?? $validated['category_id'];
                });

                $txs = [];
                $merchant = $validated['merchant'] ?? $validated['description'];

                foreach ($grouped as $catId => $groupItems) {
                    $catTotal = $groupItems->sum(function ($it) {
                        return (float) ($it['total'] ?? (($it['qty'] ?? 1) * ($it['price'] ?? 0)));
                    });

                    if ($catTotal <= 0) continue;

                    $itemNames = $groupItems->pluck('name')->slice(0, 3)->implode(', ');
                    $desc = "{$merchant}: {$itemNames}" . ($groupItems->count() > 3 ? '...' : '');

                    $tx = Transaction::create([
                        'user_id'            => $userId,
                        'account_id'         => $validated['account_id'],
                        'category_id'        => $catId ?: $validated['category_id'],
                        'type'               => $validated['type'],
                        'amount'             => $catTotal,
                        'description'        => $desc,
                        'transaction_date'   => $validated['transaction_date'],
                        'receipt_image_path' => $receiptImagePath,
                        'receipt_data'       => array_merge($receiptData, [
                            'split_items' => $groupItems->values()->toArray(),
                        ]),
                    ]);

                    $this->updateAccountBalance($tx->account_id, $tx->type, $tx->amount);
                    $txs[] = $tx;
                }

                return $txs;
            });

            // Invalidate caches & dispatch side effects
            $this->clearDashboardCaches($userId);
            foreach ($createdTransactions as $tx) {
                ProcessTransactionSideEffects::dispatch(
                    $request->user(),
                    $tx->load(['account', 'category']),
                    'store',
                    $request->user()->preferences ?? []
                );
            }

            $txIds = collect($createdTransactions)->pluck('id')->all();
            $loadedTransactions = Transaction::whereIn('id', $txIds)
                ->with(['account', 'category'])
                ->get();

            return response()->json([
                'message' => 'Transaksi berhasil dicatat dan dipecah berdasarkan kategori.',
                'count'   => count($createdTransactions),
                'data'    => TransactionResource::collection($loadedTransactions),
            ], 201);

        }

        // 4. Standard Single Transaction (both Summary and Itemized with rich receipt_data)
        $transaction = DB::transaction(function () use ($userId, $validated, $receiptImagePath, $receiptData) {
            $tx = Transaction::create([
                'user_id'            => $userId,
                'account_id'         => $validated['account_id'],
                'category_id'        => $validated['category_id'],
                'type'               => $validated['type'],
                'amount'             => $validated['amount'],
                'description'        => $validated['description'],
                'transaction_date'   => $validated['transaction_date'],
                'receipt_image_path' => $receiptImagePath,
                'receipt_data'       => $receiptData,
            ]);

            $this->updateAccountBalance($tx->account_id, $tx->type, $tx->amount);
            return $tx;
        });

        // Invalidate caches & dispatch background side effects
        $this->clearDashboardCaches($userId);

        ProcessTransactionSideEffects::dispatch(
            $request->user(),
            $transaction->load(['account', 'category']),
            'store',
            $request->user()->preferences ?? []
        );

        return response()->json([
            'message' => 'Transaksi berhasil disimpan dari struk.',
            'data'    => new TransactionResource($transaction->load(['account', 'category'])),
        ], 201);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function updateAccountBalance(int $accountId, string $type, float $amount): void
    {
        $account = Account::find($accountId);
        if (!$account) return;

        if ($type === 'income') {
            $account->increment('balance', $amount);
        } else {
            $account->decrement('balance', $amount);
        }
    }

    private function clearDashboardCaches(int $userId): void
    {
        foreach (['7days', '30days', 'this_month', 'this_year'] as $r) {
            Cache::forget("dashboard_summary:{$userId}:{$r}");
        }
    }
}
