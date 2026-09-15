<?php

namespace App\Services\SplitBill;

use Illuminate\Validation\ValidationException;

class SplitBillCalculator
{
    /**
     * Engine Perhitungan Pembagian Tagihan (Kalkulator Proporsional)
     */
    public function calculate(array $data): array
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

            // Absorb the rounding residual so the shares account for the declared
            // total exactly; otherwise the difference is silently lost.
            $assigned = array_sum(array_map(
                static fn ($p) => (float) ($p['amount_owed'] ?? 0),
                $participants
            ));
            $residual = round($totalAmount - $assigned, 2);
            if (abs($residual) >= 0.005 && $pCount > 0) {
                $participants[0]['amount_owed'] = round(
                    (float) $participants[0]['amount_owed'] + $residual, 2
                );
            }
        } elseif ($splitMode === 'percentage') {
            $pctSum = array_sum(array_map(
                static fn ($p) => (float) ($p['percentage'] ?? (100 / max(1, $pCount))),
                $participants
            ));
            if (abs($pctSum - 100) > 0.01) {
                throw ValidationException::withMessages([
                    'participants' => ['Persentase pembagian harus berjumlah 100%.'],
                ]);
            }
            foreach ($participants as &$p) {
                $pct = (float) ($p['percentage'] ?? (100 / max(1, $pCount)));
                $share = $totalAmount * ($pct / 100);
                $p['amount_owed'] = $this->applyRounding($share, $roundingMode);
            }
            unset($p);
        } elseif ($splitMode === 'exact') {
            foreach ($participants as &$p) {
                $p['amount_owed'] = max(0, (float) ($p['amount_owed'] ?? 0));
            }
            unset($p);

            $exactSum = array_sum(array_map(
                static fn ($p) => (float) ($p['amount_owed'] ?? 0),
                $participants
            ));
            if (abs($exactSum - $totalAmount) > 0.01) {
                throw ValidationException::withMessages([
                    'participants' => ['Nominal tiap partisipan harus berjumlah sama dengan total tagihan.'],
                ]);
            }
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
    public function applyRounding(float $amount, string $roundingMode): float
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
}
