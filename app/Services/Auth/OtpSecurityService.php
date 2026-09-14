<?php

namespace App\Services\Auth;

use App\Mail\SuspiciousLoginMail;
use App\Models\OtpCode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpSecurityService
{
    /**
     * Mengecek apakah email dikenakan penalty.
     * Jika ya, mengembalikan response JSON error dengan sisa waktu.
     * Jika tidak, mengembalikan null.
     */
    public function checkOtpPenalty(string $email): ?JsonResponse
    {
        $penaltyKey = "otp_penalty_{$email}";
        if (Cache::has($penaltyKey)) {
            $unblockAt = Cache::get($penaltyKey);
            $unblockTime = Carbon::parse($unblockAt);
            
            if (Carbon::now()->lessThan($unblockTime)) {
                $minutesLeft = Carbon::now()->diffInMinutes($unblockTime) + 1;
                
                // Format pesan waktu
                if ($minutesLeft >= 60) {
                    $hours = ceil($minutesLeft / 60);
                    $timeString = $hours . ' jam';
                } else {
                    $timeString = $minutesLeft . ' menit';
                }
                
                return response()->json([
                    'message' => "Sisa percobaan Anda telah habis. Silakan tunggu {$timeString} untuk meminta atau mengirim ulang kode OTP baru."
                ], 429);
            } else {
                Cache::forget($penaltyKey);
            }
        }
        
        return null;
    }

    /**
     * Menangani percobaan OTP yang gagal. 
     * Akan menaikkan counter dan menerapkan penalty jika limit tercapai.
     */
    public function handleFailedOtp(string $email): JsonResponse
    {
        $failsKey = "otp_fails_{$email}";
        $currentFails = (int) Cache::get($failsKey, 0);
        $fails = $currentFails + 1;
        Cache::put($failsKey, $fails, Carbon::now()->addHours(24));

        // Tentukan kelipatan penalti (5, 10, 15, >= 20)
        if ($fails >= 5 && $fails % 5 === 0) {
            $penaltyKey = "otp_penalty_{$email}";
            
            if ($fails === 5) {
                $blockUntil = Carbon::now()->addMinutes(5);
            } elseif ($fails === 10) {
                $blockUntil = Carbon::now()->addMinutes(15);
            } elseif ($fails === 15) {
                $blockUntil = Carbon::now()->addMinutes(30);
            } else {
                // Fails >= 20
                $blockUntil = Carbon::now()->addDay();
            }

            // Set penalty (simpan sebagai ISO string untuk konsistensi di database cache)
            Cache::put($penaltyKey, $blockUntil->toIso8601String(), $blockUntil);
            
            // Hapus OTP aktif jika ada
            OtpCode::where('email', $email)->delete();

            // Kirim email peringatan
            try {
                Mail::to($email)->send(new SuspiciousLoginMail($email));
            } catch (\Exception $e) {
                Log::error('Mail Error (Suspicious Login): ' . $e->getMessage());
            }

            return $this->checkOtpPenalty($email);
        }

        $sisa = 5 - ($fails % 5);
        return response()->json([
            'message' => "Kode OTP tidak valid atau sudah kadaluarsa. Sisa percobaan: {$sisa}"
        ], 422);
    }

    /**
     * Membersihkan cache counter kegagalan dan penalti jika OTP berhasil diverifikasi.
     */
    public function clearOtpFails(string $email): void
    {
        Cache::forget("otp_fails_{$email}");
        Cache::forget("otp_penalty_{$email}");
    }
}
