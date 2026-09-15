<?php

namespace App\Services\SplitBill;

use App\Models\SplitBill;
use App\Models\SplitBillParticipant;
use Carbon\Carbon;

class SplitBillShareFormatter
{
    /**
     * Generate Teks Tagihan WhatsApp Terformat Natural, Santun & Profesional
     */
    public function formatWhatsAppMessage(SplitBill $splitBill, ?SplitBillParticipant $targetParticipant = null): string
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
                if ($myItems && $myItems->isNotEmpty()) {
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
