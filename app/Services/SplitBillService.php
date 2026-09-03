<?php

namespace App\Services;

use App\Models\Account;
use App\Models\SplitBill;
use App\Models\SplitBillParticipant;
use App\Models\SplitBillItem;
use App\Models\SplitBillItemParticipant;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SplitBillService
{
    protected ?GamificationService $gamificationService;

    public function __construct(?GamificationService $gamificationService = null)
    {
        $this->gamificationService = $gamificationService;
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
     * Engine Perhitungan Pembagian Tagihan (Kalkulator Proporsional)
     */
    public function calculateSplit(array $data): array
    {
        $splitMode = $data['split_mode'] ?? 'equal';
        $roundingMode = $data['rounding_mode'] ?? 'none';

        $participants = $data['participants'] ?? [];
        if (empty($participants)) {
            throw new \InvalidArgumentException("Minimal harus ada 1 partisipan.");
        }

        $items = $data['items'] ?? [];
        $subtotal = 0;

        if ($splitMode === 'itemized' && !empty($items)) {
            foreach ($items as &$item) {
                $item['quantity'] = max(1, (int) ($item['quantity'] ?? 1));
                $item['price'] = (float) ($item['price'] ?? 0);
                $item['subtotal'] = $item['price'] * $item['quantity'];
                $subtotal += $item['subtotal'];
            }
            unset($item);
        } else {
            $subtotal = (float) ($data['subtotal'] ?? 0);
            if ($subtotal <= 0 && !empty($data['total_amount'])) {
                $subtotal = (float) $data['total_amount'];
            }
        }

        $taxPercent = (float) ($data['tax_percent'] ?? 0);
        $taxAmount = isset($data['tax_amount']) && $data['tax_amount'] > 0 
            ? (float) $data['tax_amount'] 
            : round($subtotal * ($taxPercent / 100), 2);

        $servicePercent = (float) ($data['service_percent'] ?? 0);
        $serviceAmount = isset($data['service_amount']) && $data['service_amount'] > 0 
            ? (float) $data['service_amount'] 
            : round($subtotal * ($servicePercent / 100), 2);

        $discountAmount = (float) ($data['discount_amount'] ?? 0);

        $totalAmount = max(0, round($subtotal + $taxAmount + $serviceAmount - $discountAmount, 2));

        $pCount = count($participants);

        // Hitung bagian masing-masing partisipan
        if ($splitMode === 'equal') {
            $baseShare = $pCount > 0 ? ($totalAmount / $pCount) : $totalAmount;
            foreach ($participants as &$p) {
                $p['amount_owed'] = $this->applyRounding($baseShare, $roundingMode);
            }
            unset($p);
        } elseif ($splitMode === 'percentage') {
            foreach ($participants as &$p) {
                $pct = (float) ($p['percentage'] ?? (100 / max(1, $pCount)));
                $share = $totalAmount * ($pct / 100);
                $p['amount_owed'] = $this->applyRounding($share, $roundingMode);
            }
            unset($p);
        } elseif ($splitMode === 'exact') {
            foreach ($participants as &$p) {
                $p['amount_owed'] = (float) ($p['amount_owed'] ?? 0);
            }
            unset($p);
        } elseif ($splitMode === 'itemized') {
            // Hitung subtotal tiap partisipan berdasarkan item yang dipesan
            $participantSubtotals = array_fill(0, $pCount, 0);

            foreach ($items as $item) {
                $pRefs = $item['participant_ids'] ?? [];
                $countAssigned = count($pRefs);
                if ($countAssigned === 0) continue;

                $sharePerAssigned = $item['subtotal'] / $countAssigned;

                foreach ($participants as $pIdx => $p) {
                    $pRef = $p['temp_id'] ?? $p['name'];
                    if (in_array($pRef, $pRefs) || in_array($pIdx, $pRefs)) {
                        $participantSubtotals[$pIdx] += $sharePerAssigned;
                    }
                }
            }

            // Proporsional tax, service, discount
            foreach ($participants as $pIdx => &$p) {
                $pSubtotal = $participantSubtotals[$pIdx];
                $ratio = $subtotal > 0 ? ($pSubtotal / $subtotal) : (1 / max(1, $pCount));

                $pTax = $taxAmount * $ratio;
                $pService = $serviceAmount * $ratio;
                $pDiscount = $discountAmount * $ratio;

                $pTotal = max(0, $pSubtotal + $pTax + $pService - $pDiscount);
                $p['amount_owed'] = $this->applyRounding($pTotal, $roundingMode);
            }
            unset($p);
        }

        return [
            'subtotal'        => $subtotal,
            'tax_percent'     => $taxPercent,
            'tax_amount'      => $taxAmount,
            'service_percent' => $servicePercent,
            'service_amount'  => $serviceAmount,
            'discount_amount' => $discountAmount,
            'total_amount'    => $totalAmount,
            'participants'    => $participants,
            'items'           => $items,
        ];
    }

    /**
     * Terapkan pembulatan ke nominal tertentu
     */
    private function applyRounding(float $amount, string $roundingMode): float
    {
        switch ($roundingMode) {
            case 'up_100':
                return ceil($amount / 100) * 100;
            case 'up_1000':
                return ceil($amount / 1000) * 1000;
            case 'down_100':
                return floor($amount / 100) * 100;
            case 'none':
            default:
                return round($amount);
        }
    }

    /**
     * Catat pelunasan partisipan (Mark Paid / Partial)
     */
    public function markParticipantPayment(SplitBill $splitBill, SplitBillParticipant $participant, float $amountPaid, ?string $notes = null): SplitBillParticipant
    {
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
            // Jika sudah ada transaksi sebelumnya, update nominalnya
            if ($splitBill->my_transaction_id) {
                $existingTx = Transaction::find($splitBill->my_transaction_id);
                if ($existingTx) {
                    $diff = $creator->amount_owed - $existingTx->amount;
                    $existingTx->amount = $creator->amount_owed;
                    $existingTx->save();

                    $account->balance -= $diff;
                    $account->save();

                    return $existingTx;
                }
            }

            // Buat transaksi pengeluaran baru
            $tx = Transaction::create([
                'user_id'          => $splitBill->user_id,
                'account_id'       => $account->id,
                'category_id'      => $categoryId ?? $splitBill->category_id ?? 1,
                'type'             => 'expense',
                'amount'           => $creator->amount_owed,
                'description'      => "Bagian Tagihan: {$splitBill->title}",
                'transaction_date' => $splitBill->bill_date ?? Carbon::today(),
            ]);

            $account->balance -= $creator->amount_owed;
            $account->save();

            $splitBill->my_transaction_id = $tx->id;
            $splitBill->account_id = $account->id;
            $splitBill->save();

            return $tx;
        });
    }

    /**
     * Generate Teks Tagihan WhatsApp Terformat Natural, Santun & Profesional
     */
    public function generateWhatsAppMessage(SplitBill $splitBill, ?SplitBillParticipant $targetParticipant = null): string
    {
        $title = $splitBill->title;
        $date = Carbon::parse($splitBill->bill_date)->translatedFormat('d M Y');
        $totalFormatted = "Rp " . number_format($splitBill->total_amount, 0, ',', '.');

        $paymentText = "";
        if (!empty($splitBill->payment_info)) {
            $info = $splitBill->payment_info;
            $bank = $info['bank_name'] ?? 'Transfer';
            $accNo = $info['account_number'] ?? '-';
            $holder = $info['account_holder'] ?? '';
            $paymentText = "\nPembayaran bisa ditransfer ke:\n*{$bank}*: `{$accNo}`" . ($holder ? " (a.n. {$holder})" : "");
        }

        // Pesan Personal (1 Orang)
        if ($targetParticipant) {
            $amountFormatted = "Rp " . number_format($targetParticipant->amount_owed, 0, ',', '.');
            
            $text = "Halo {$targetParticipant->name},\n\n";
            $text .= "Berikut rincian patungan untuk *{$title}* ({$date}) ya:\n";
            $text .= "• Total bagianmu: *{$amountFormatted}*\n";

            // Jika mode itemized, cantumkan menu yang dipesan
            if ($splitBill->split_mode === 'itemized') {
                $myItems = $targetParticipant->items;
                if ($myItems->isNotEmpty()) {
                    $text .= "\nMenu pesananmu:\n";
                    foreach ($myItems as $item) {
                        $fraction = $item->pivot->split_fraction ?? 1.0;
                        $itemPrice = $item->subtotal * $fraction;
                        $text .= "• {$item->name}: Rp " . number_format($itemPrice, 0, ',', '.') . "\n";
                    }
                }
            }

            $text .= $paymentText;
            $text .= "\n\nKalau sudah transfer, tolong kabari ya. Terima kasih banyak!";
            return $text;
        }

        // Pesan Rekap Grup (Seluruh Partisipan)
        $text = "Halo teman-teman,\n\n";
        $text .= "Berikut rincian patungan untuk *{$title}* ({$date}):\n";
        $text .= "• Total Tagihan: *{$totalFormatted}*\n\n";
        $text .= "Rincian Pembagian:\n";

        $idx = 1;
        foreach ($splitBill->participants as $p) {
            $statusLabel = $p->is_creator ? '(sudah ditalangi)' : ($p->status === 'paid' ? '[Lunas]' : '[Belum Transfer]');
            $amount = "Rp " . number_format($p->amount_owed, 0, ',', '.');
            $text .= "{$idx}. *{$p->name}*: {$amount} {$statusLabel}\n";
            $idx++;
        }

        $text .= $paymentText;
        $text .= "\n\nJika sudah transfer, mohon konfirmasi ya. Terima kasih semuanya!";

        return $text;
    }
}
