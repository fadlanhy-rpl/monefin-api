<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SendOtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccountSecurityController extends Controller
{
    /**
     * POST /api/auth/secure-account
     * Body: { token }
     */
    public function secureAccount(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $actionKey  = 'secure_login_token_' . $request->token;
        $actionData = Cache::get($actionKey);

        if (!$actionData) {
            return response()->json([
                'message' => 'Tautan pengamanan tidak valid atau telah kedaluwarsa.',
            ], 400);
        }

        $user = User::find($actionData['user_id']);
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        // 1. Cabut token sesi penyusup jika masih ada
        if (!empty($actionData['token_id'])) {
            $compromisedToken = $user->tokens()->where('id', $actionData['token_id'])->first();
            if ($compromisedToken) {
                $revokerDetails = [
                    'device' => 'Pusat Keamanan (Email)',
                    'ip'     => $request->ip() ?: 'IP tidak tersimpan',
                    'time'   => now()->translatedFormat('d M Y, H:i'),
                ];
                Cache::put('revoked_' . $compromisedToken->token, $revokerDetails, now()->addDay());
                $compromisedToken->delete();
            }
        }

        // 2. Buatkan OTP Reset Password baru agar pemilik akun bisa langsung ganti password
        $otp = OtpCode::generateFor($user->email, 'reset', 15);

        try {
            Mail::to($user->email)->send(new SendOtpMail($otp, 'reset'));
        } catch (\Exception $e) {
            Log::error('Mail Error (Secure Account Reset OTP): ' . $e->getMessage());
        }

        // 3. Hapus action token agar tidak bisa dipakai berulang kali
        Cache::forget($actionKey);

        return response()->json([
            'message' => 'Sesi mencurigakan telah dicabut dan kode OTP ganti password telah dikirim ke email Anda.',
            'data'    => [
                'email'       => $user->email,
                'device_name' => $actionData['device_name'] ?? 'Perangkat Tidak Dikenal',
            ],
        ]);
    }
}
