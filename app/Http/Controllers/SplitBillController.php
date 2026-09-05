<?php

namespace App\Http\Controllers;

use App\Models\SplitBill;
use App\Models\SplitBillParticipant;
use App\Services\SplitBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SplitBillController extends Controller
{
    public function __construct(
        protected SplitBillService $splitBillService
    ) {}

    /**
     * List seluruh Split Bill user beserta ringkasan
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->query('status'); // all, active, settled
        $search = $request->query('search');

        $query = SplitBill::where('user_id', $user->id)
            ->with(['participants', 'account', 'category', 'transaction'])
            ->latest('bill_date');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('participants', function ($pQuery) use ($search) {
                      $pQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $bills = $query->paginate($request->query('per_page', 15));
        $summary = $this->splitBillService->getSummary($user);

        return response()->json([
            'status'  => 'success',
            'data'    => $bills->items(),
            'meta'    => [
                'current_page' => $bills->currentPage(),
                'last_page'    => $bills->lastPage(),
                'per_page'     => $bills->perPage(),
                'total'        => $bills->total(),
            ],
            'summary' => $summary,
        ]);
    }

    /**
     * Simpan Split Bill Baru
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'bill_date'          => ['required', 'date'],
            'account_id'         => ['nullable', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'category_id'        => ['nullable', Rule::exists('categories', 'id')->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)->orWhereNull('user_id');
            })],
            'subtotal'           => ['nullable', 'numeric', 'min:0'],
            'tax_percent'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_amount'         => ['nullable', 'numeric', 'min:0'],
            'service_percent'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_amount'     => ['nullable', 'numeric', 'min:0'],
            'discount_amount'    => ['nullable', 'numeric', 'min:0'],
            'total_amount'       => ['nullable', 'numeric', 'min:0'],
            'split_mode'         => ['required', 'in:equal,itemized,exact,percentage'],
            'rounding_mode'      => ['nullable', 'in:none,up_100,up_1000,down_100'],
            'payment_info'       => ['nullable', 'array'],
            'record_my_expense'  => ['nullable', 'boolean'],
            'participants'       => ['required', 'array', 'min:1'],
            'participants.*.name'          => ['required', 'string'],
            'participants.*.phone_number'  => ['nullable', 'string'],
            'participants.*.is_creator'    => ['nullable', 'boolean'],
            'participants.*.amount_owed'   => ['nullable', 'numeric'],
            'participants.*.percentage'    => ['nullable', 'numeric'],
            'items'                        => ['nullable', 'array'],
            'items.*.name'                 => ['required_with:items', 'string'],
            'items.*.price'                => ['required_with:items', 'numeric', 'min:0'],
            'items.*.quantity'             => ['nullable', 'integer', 'min:1'],
            'items.*.participant_ids'      => ['nullable', 'array'],
        ]);

        $splitBill = $this->splitBillService->createSplitBill($request->user(), $validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Pembagian tagihan berhasil dibuat!',
            'data'    => $splitBill,
        ], 201);
    }

    /**
     * Preview kalkulasi pembagian sebelum disimpan
     */
    public function calculatePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subtotal'           => ['nullable', 'numeric', 'min:0'],
            'tax_percent'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_amount'         => ['nullable', 'numeric', 'min:0'],
            'service_percent'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_amount'     => ['nullable', 'numeric', 'min:0'],
            'discount_amount'    => ['nullable', 'numeric', 'min:0'],
            'total_amount'       => ['nullable', 'numeric', 'min:0'],
            'split_mode'         => ['required', 'in:equal,itemized,exact,percentage'],
            'rounding_mode'      => ['nullable', 'in:none,up_100,up_1000,down_100'],
            'participants'       => ['required', 'array', 'min:1'],
            'participants.*.name'          => ['required', 'string'],
            'participants.*.phone_number'  => ['nullable', 'string'],
            'participants.*.is_creator'    => ['nullable', 'boolean'],
            'participants.*.amount_owed'   => ['nullable', 'numeric'],
            'participants.*.percentage'    => ['nullable', 'numeric'],
            'items'                        => ['nullable', 'array'],
            'items.*.name'                 => ['required_with:items', 'string'],
            'items.*.price'                => ['required_with:items', 'numeric', 'min:0'],
            'items.*.quantity'             => ['nullable', 'integer', 'min:1'],
            'items.*.participant_ids'      => ['nullable', 'array'],
        ]);

        $result = $this->splitBillService->calculateSplit($validated);

        return response()->json([
            'status' => 'success',
            'data'   => $result,
        ]);
    }

    /**
     * Detail Split Bill
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $splitBill = SplitBill::where('user_id', $request->user()->id)
            ->with(['participants', 'items.participants', 'account', 'category', 'transaction'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => $splitBill,
        ]);
    }

    /**
     * Hapus Split Bill
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $splitBill = SplitBill::where('user_id', $request->user()->id)->findOrFail($id);
        $splitBill->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Pembagian tagihan berhasil dihapus.',
        ]);
    }

    /**
     * Catat Pelunasan Partisipan
     */
    public function markPayment(Request $request, int $id, int $participantId): JsonResponse
    {
        $splitBill = SplitBill::where('user_id', $request->user()->id)->findOrFail($id);
        $participant = $splitBill->participants()->findOrFail($participantId);

        $validated = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0'],
            'notes'       => ['nullable', 'string', 'max:255'],
        ]);

        $updatedParticipant = $this->splitBillService->markParticipantPayment(
            $splitBill,
            $participant,
            (float) $validated['amount_paid'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Status pembayaran partisipan berhasil diperbarui.',
            'data'    => $updatedParticipant,
            'bill'    => $splitBill->fresh(['participants', 'account']),
        ]);
    }

    /**
     * Catat bagian user ke transaksi dompet MoneFin
     */
    public function recordExpense(Request $request, int $id): JsonResponse
    {
        $splitBill = SplitBill::where('user_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'account_id'  => ['required', 'exists:accounts,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $tx = $this->splitBillService->recordMyShareToTransaction(
            $splitBill,
            (int) $validated['account_id'],
            $validated['category_id'] ?? null
        );

        return response()->json([
            'status'      => 'success',
            'message'     => 'Bagian pengeluaran Anda berhasil dicatat ke dompet!',
            'transaction' => $tx,
            'bill'        => $splitBill->fresh(['transaction', 'account']),
        ]);
    }

    /**
     * Ambil template teks WhatsApp untuk dikirim ke teman
     */
    public function whatsappText(Request $request, int $id): JsonResponse
    {
        $splitBill = SplitBill::where('user_id', $request->user()->id)
            ->with(['participants', 'items.participants'])
            ->findOrFail($id);

        $participantId = $request->query('participant_id');
        $targetParticipant = null;
        if ($participantId) {
            $targetParticipant = $splitBill->participants->firstWhere('id', (int) $participantId);
        }

        $text = $this->splitBillService->generateWhatsAppMessage($splitBill, $targetParticipant);

        $phone = $targetParticipant?->phone_number;
        $whatsappUrl = "https://wa.me/?text=" . rawurlencode($text);

        if (!empty($phone)) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (str_starts_with($cleanPhone, '0')) {
                $cleanPhone = '62' . substr($cleanPhone, 1);
            } elseif (str_starts_with($cleanPhone, '8')) {
                $cleanPhone = '62' . $cleanPhone;
            }
            if (!empty($cleanPhone)) {
                $whatsappUrl = "https://wa.me/{$cleanPhone}?text=" . rawurlencode($text);
            }
        }

        return response()->json([
            'status' => 'success',
            'data'   => [
                'text'          => $text,
                'phone_number'  => $targetParticipant?->phone_number,
                'whatsapp_url'  => $whatsappUrl,
            ],
        ]);
    }
}
