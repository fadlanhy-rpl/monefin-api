<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\AuthTokenService;
use App\Services\Auth\OtpSecurityService;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TwoFactorAuthController extends Controller
{
    public function __construct(
        protected AuthTokenService $authTokenService,
        protected OtpSecurityService $otpSecurityService,
        protected GamificationService $gamificationService
    ) {}

    /**
     * POST /api/auth/verify-2fa
     * Body: { email, otp }
     */
    public function verify2fa(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string|size:6',
        ]);

        if ($penaltyResponse = $this->otpSecurityService->checkOtpPenalty($request->email)) {
            return $penaltyResponse;
        }

        $otpRecord = OtpCode::verify($request->email, '2fa', $request->otp);

        if (!$otpRecord) {
            return $this->otpSecurityService->handleFailedOtp($request->email);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        $otpRecord->delete();

        // Bersihkan cache counter gagal jika berhasil login
        $this->otpSecurityService->clearOtpFails($request->email);

        $token = $this->authTokenService->createToken($user, $request);

        return response()->json([
            'data'    => ['user' => $user->fresh(), 'token' => $token],
            'message' => 'Verifikasi 2FA berhasil. Selamat datang!',
        ]);
    }

    /**
     * POST /api/auth/2fa/toggle
     * Body: { enabled: boolean }
     */
    public function toggle2fa(Request $request): JsonResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $user = $request->user();
        $user->update(['two_factor_enabled' => $request->enabled]);

        if ($request->enabled) {
            try {
                $this->gamificationService->awardXP($user, 75, 'Aktivasi Two-Factor Authentication');
                $this->gamificationService->updateAchievementProgress($user, 'security_2fa', 1);
            } catch (\Exception $e) {
                Log::error('Gamification Error (2FA toggle): ' . $e->getMessage());
            }
        }

        return response()->json([
            'data'    => ['user' => $user->fresh()],
            'message' => $request->enabled
                ? 'Two-Factor Authentication berhasil diaktifkan.'
                : 'Two-Factor Authentication berhasil dinonaktifkan.',
        ]);
    }
}
