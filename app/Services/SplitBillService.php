<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SplitBill;
use App\Models\SplitBillParticipant;
use App\Models\SplitBillItem;
use App\Models\SplitBillItemParticipant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SplitBill\SplitBillCalculator;
use App\Services\SplitBill\SplitBillShareFormatter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SplitBillService
{
    protected ?GamificationService $gamificationService;
    protected SplitBillCalculator $calculator;
    protected SplitBillShareFormatter $shareFormatter;

    public function __construct(
        ?GamificationService $gamificationService = null,
        ?SplitBillCalculator $calculator = null,
        ?SplitBillShareFormatter $shareFormatter = null
    ) {
        $this->gamificationService = $gamificationService;
        $this->calculator = $calculator ?? new SplitBillCalculator();
        $this->shareFormatter = $shareFormatter ?? new SplitBillShareFormatter();
    }

    /**
     * Dapatkan ringkasan statistik Split Bill user
     */
    public function getSummary(User $user): array
    {
        $activeBills = SplitBill::where('user_id', $user->id)
            ->where('status', 'active')
            ->get();

        $totalActiveCount = $activeBills->count();

        // Total uang yang harus ditagih ke teman (selain creator) yang statusnya belum lunas
        $totalOwedToMe = SplitBillParticipant::whereHas('splitBill', function ($query) use ($user) {
            $query->where('user_id', $user->id)->where('status', 'active');
        })
        ->where('is_creator', false)
        ->whereIn('status', ['unpaid', 'partial'])
        ->selectRaw('SUM(amount_owed - amount_paid) as total_unpaid')
        ->value('total_unpaid') ?? 0;

        $settledCount = SplitBill::where('user_id', $user->id)
            ->where('status', 'settled')
            ->count();

        $totalBillsCount = SplitBill::where('user_id', $user->id)->count();

        return [
            'total_active'     => $totalActiveCount,
            'total_owed_to_me' => (float) $totalOwedToMe,
            'total_settled'    => $settledCount,
            'total_bills'      => $totalBillsCount,
        ];
    }

    /**
     * Buat Split Bill baru dengan kalkulasi otomatis
     */
    public function createSplitBill(User $user, array $data): SplitBill
    {
        return DB::transaction(function () use ($user, $data) {
            $calculated = $this->calculateSplit($data);

            $splitBill = SplitBill::create([
                'user_id'            => $user->id,
                'title'              => $data['title'],
                'description'        => $data['description'] ?? null,
                'bill_date'          => $data['bill_date'] ?? Carbon::today(),
                'account_id'         => $data['account_id'] ?? null,
                'category_id'        => $data['category_id'] ?? null,
                'subtotal'           => $calculated['subtotal'],
                'tax_percent'        => $calculated['tax_percent'],
                'tax_amount'         => $calculated['tax_amount'],
                'service_percent'    => $calculated['service_percent'],
                'service_amount'     => $calculated['service_amount'],
                'discount_amount'    => $calculated['discount_amount'],
                'total_amount'       => $calculated['total_amount'],
                'split_mode'         => $data['split_mode'] ?? 'equal',
                'rounding_mode'      => $data['rounding_mode'] ?? 'none',
                'payment_info'       => $data['payment_info'] ?? null,
                'receipt_image_path' => $data['receipt_image_path'] ?? null,
                'status'             => 'active',
            ]);

            // Simpan Partisipan
            $participantMap = [];
            foreach ($calculated['participants'] as $pData) {
                $participant = SplitBillParticipant::create([
                    'split_bill_id' => $splitBill->id,
                    'name'          => $pData['name'],
                    'phone_number'  => $pData['phone_number'] ?? null,
                    'is_creator'    => $pData['is_creator'] ?? false,
                    'amount_owed'   => $pData['amount_owed'],
                    'amount_paid'   => ($pData['is_creator'] ?? false) ? $pData['amount_owed'] : ($pData['amount_paid'] ?? 0),
                    'status'        => ($pData['is_creator'] ?? false) ? 'paid' : ($pData['status'] ?? 'unpaid'),
                    'paid_at'       => ($pData['is_creator'] ?? false) ? Carbon::now() : null,
                    'notes'         => $pData['notes'] ?? null,
                ]);

                $participantMap[$pData['temp_id'] ?? $pData['name']] = $participant->id;
            }

            // Simpan Items jika mode itemized
            if (($data['split_mode'] ?? 'equal') === 'itemized' && !empty($calculated['items'])) {
                foreach ($calculated['items'] as $itemData) {
                    $item = SplitBillItem::create([
                        'split_bill_id' => $splitBill->id,
                        'name'          => $itemData['name'],
                        'price'         => $itemData['price'],
                        'quantity'      => $itemData['quantity'] ?? 1,
                        'subtotal'      => $itemData['subtotal'],
                    ]);

                    if (!empty($itemData['participant_ids'])) {
                        $pCount = count($itemData['participant_ids']);
                        $fraction = $pCount > 0 ? (1.0 / $pCount) : 1.0;

                        foreach ($itemData['participant_ids'] as $pRef) {
                            $pId = $participantMap[$pRef] ?? $pRef;
                            SplitBillItemParticipant::create([
                                'split_bill_item_id'        => $item->id,
                                'split_bill_participant_id' => $pId,
                                'split_fraction'            => $fraction,
                            ]);
                        }
                    }
                }
            }

            // Jika opsi record_my_expense aktif dan account_id tersedia
            if (!empty($data['record_my_expense']) && !empty($data['account_id'])) {
                $creatorParticipant = $splitBill->participants()->where('is_creator', true)->first();
                if ($creatorParticipant) {
                    $this->recordMyShareToTransaction($splitBill, (int) $data['account_id'], $data['category_id'] ?? null);
                }
            }

            // Beri XP Gamifikasi untuk pembuatan Split Bill
            if ($this->gamificationService) {
                try {
                    $this->gamificationService->awardXP($user, 20, "Membuat Pembagian Tagihan: {$splitBill->title}");
                } catch (\Exception $e) {
                    Log::warning('Gamification awardXP failed: ' . $e->getMessage());
                }
            }

            return $splitBill->load(['participants', 'items.participants']);
        });
    }

    /**
     * Engine Perhitungan Pembagian Tagihan (Delegasi ke SplitBillCalculator)
     */
    public function calculateSplit(array $data): array
    {
        return $this->calculator->calculate($data);
    }

    /**
     * Catat pelunasan partisipan (Mark Paid / Partial)
     */
    public function markParticipantPayment(SplitBill $splitBill, SplitBillParticipant $participant, float $amountPaid, ?string $notes = null): SplitBillParticipant
    {
        if ($amountPaid < 0) {
            throw ValidationException::withMessages([
                'amount_paid' => ['Jumlah pembayaran tidak boleh negatif.'],
            ]);
        }

        if ($amountPaid > (float) $participant->amount_owed) {
            throw ValidationException::withMessages([
                'amount_paid' => ['Jumlah pembayaran tidak boleh melebihi sisa tagihan partisipan.'],
            ]);
        }

        return DB::transaction(function () use ($splitBill, $participant, $amountPaid, $notes) {
            $participant->amount_paid = $amountPaid;
            $participant->notes = $notes ?? $participant->notes;

            if ($participant->amount_paid >= $participant->amount_owed) {
                $participant->status = 'paid';
                $participant->paid_at = Carbon::now();
            } elseif ($participant->amount_paid > 0) {
                $participant->status = 'partial';
            } else {
                $participant->status = 'unpaid';
                $participant->paid_at = null;
            }

            $participant->save();

            // Cek apakah seluruh partisipan sudah lunas
            $allPaid = $splitBill->participants()->where('status', '!=', 'paid')->count() === 0;
            if ($allPaid && $splitBill->status !== 'settled') {
                $splitBill->status = 'settled';
                $splitBill->save();

                // Beri XP Gamifikasi jika tagihan tuntas selesai!
                if ($this->gamificationService) {
                    try {
                        $this->gamificationService->awardXP($splitBill->user, 30, "Tagihan Lunas Selesai: {$splitBill->title}");
                    } catch (\Exception $e) {
                        Log::warning('Gamification awardXP failed: ' . $e->getMessage());
                    }
                }
            } elseif (!$allPaid && $splitBill->status === 'settled') {
                $splitBill->status = 'active';
                $splitBill->save();
            }

            return $participant;
        });
    }

    /**
     * Otomatis catat bagian pengeluaran user ke transaksi MoneFin
     */
    public function recordMyShareToTransaction(SplitBill $splitBill, int $accountId, ?int $categoryId = null): ?Transaction
    {
        $creator = $splitBill->participants()->where('is_creator', true)->first();
        if (!$creator || $creator->amount_owed <= 0) {
            return null;
        }

        $account = Account::where('id', $accountId)->where('user_id', $splitBill->user_id)->first();
        if (!$account) {
            throw new \InvalidArgumentException("Akun pembayaran tidak ditemukan.");
        }

        return DB::transaction(function () use ($splitBill, $creator, $account, $categoryId) {
            $freshBill = SplitBill::whereKey($splitBill->getKey())->lockForUpdate()->first();
            if (!$freshBill) {
                throw new \InvalidArgumentException("Tagihan tidak ditemukan.");
            }

            // Jika sudah ada transaksi sebelumnya, update nominalnya
            if ($freshBill->my_transaction_id) {
                $existingTx = Transaction::find($freshBill->my_transaction_id);
                if ($existingTx) {
                    $diff = $creator->amount_owed - $existingTx->amount;
                    $existingTx->amount = $creator->amount_owed;
                    $existingTx->save();

                    if ($diff > 0) {
                        $account->decrement('balance', $diff);
                    } elseif ($diff < 0) {
                        $account->increment('balance', abs($diff));
                    }

                    return $existingTx;
                }
            }

            // Buat transaksi pengeluaran baru
            $tx = Transaction::create([
                'user_id'          => $freshBill->user_id,
                'account_id'       => $account->id,
                'category_id'      => $categoryId ?? $freshBill->category_id ?? null,
                'type'             => 'expense',
                'amount'           => $creator->amount_owed,
                'description'      => "Bagian Tagihan: {$freshBill->title}",
                'transaction_date' => $freshBill->bill_date ?? Carbon::today(),
            ]);

            $account->decrement('balance', $creator->amount_owed);

            $freshBill->my_transaction_id = $tx->id;
            $freshBill->account_id = $account->id;
            $freshBill->save();

            return $tx;
        });
    }

    /**
     * Generate Teks Tagihan WhatsApp Terformat Natural, Santun & Profesional (Delegasi ke SplitBillShareFormatter)
     */
    public function generateWhatsAppMessage(SplitBill $splitBill, ?SplitBillParticipant $targetParticipant = null): string
    {
        return $this->shareFormatter->formatWhatsAppMessage($splitBill, $targetParticipant);
    }
}
