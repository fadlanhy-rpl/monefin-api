<?php

namespace App\Services\Auth;

use App\Mail\NewLoginAlertMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthTokenService
{
    public function __construct(
        protected DeviceDetectorService $deviceDetector
    ) {}

    /**
     * Buat Sanctum personal access token dengan info device & audit trail.
     */
    public function createToken(User $user, Request $request): string
    {
        $deviceName = $this->deviceDetector->detectDevice($request);

        // Hapus sesi lama di browser/device yang persis sama agar tidak menumpuk, catat revoker di Cache
        $oldTokens = $user->tokens()->where('name', $deviceName)->get();
        $revokerDetails = [
            'device' => $deviceName,
            'ip'     => $request->ip() ?: 'IP tidak tersimpan',
            'time'   => now()->translatedFormat('d M Y, H:i')
        ];
        foreach ($oldTokens as $oldT) {
            Cache::put('revoked_' . $oldT->token, $revokerDetails, now()->addDay());
            $oldT->delete();
        }

        $tokenResult = $user->createToken($deviceName);

        // Update menggunakan ID — dijamin akurat, tidak bisa salah token
        $tokenResult->accessToken->forceFill([
            'device_name' => $deviceName,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ])->save();

        // Buat action token untuk pengamanan akun jika login bukan oleh pemilik
        $actionToken = Str::random(64);
        Cache::put('secure_login_token_' . $actionToken, [
            'token_id'    => $tokenResult->accessToken->id,
            'user_id'     => $user->id,
            'email'       => $user->email,
            'device_name' => $deviceName,
            'ip_address'  => $request->ip(),
            'login_time'  => now()->translatedFormat('d M Y, H:i'),
        ], now()->addDays(2));

        // Kirim email peringatan login baru ke user
        try {
            Mail::to($user->email)->send(new NewLoginAlertMail(
                $user->name,
                $user->email,
                $deviceName,
                $request->ip() ?: 'IP tidak tersimpan',
                now()->translatedFormat('d M Y, H:i'),
                $actionToken
            ));
        } catch (\Exception $e) {
            Log::error('Mail Error (New Login Alert): ' . $e->getMessage());
        }

        return $tokenResult->plainTextToken;
    }
}
