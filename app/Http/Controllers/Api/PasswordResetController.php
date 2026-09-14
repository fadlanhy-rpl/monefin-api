<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SendOtpMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\AuthTokenService;
use App\Services\Auth\OtpSecurityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{
    public function __construct(
        protected AuthTokenService $authTokenService,
        protected OtpSecurityService $otpSecurityService
    ) {}

    /**
     * POST /api/auth/verify-email
     * Body: { email, otp }
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string|size:6',
        ]);

        $otpRecord = OtpCode::verify($request->email, 'verification', $request->otp);

        if (!$otpRecord) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kadaluarsa.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        $user->update(['email_verified_at' => Carbon::now()]);
        $otpRecord->delete();

        // Auto-login setelah verifikasi
        $token = $this->authTokenService->createToken($user, $request);

        return response()->json([
            'data'    => ['user' => $user->fresh(), 'token' => $token],
            'message' => 'Email berhasil diverifikasi. Selamat datang!',
        ]);
    }

    /**
     * POST /api/auth/resend-otp
     * Body: { email, type }
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'type'  => 'required|in:verification,reset,2fa',
        ]);

        if ($penaltyResponse = $this->otpSecurityService->checkOtpPenalty($request->email)) {
            return $penaltyResponse;
        }

        // Respons generik untuk mencegah email enumeration.
        $genericResponse = response()->json(['message' => 'Jika email terdaftar, kode OTP akan segera dikirimkan.']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $genericResponse;
        }

        $otp = OtpCode::generateFor($request->email, $request->type, 5);

        try {
            Mail::to($request->email)->send(new SendOtpMail($otp, $request->type));
        } catch (\Exception $e) {
            Log::error('Mail Error (Resend OTP): ' . $e->getMessage());
        }

        return $genericResponse;
    }

    /**
     * POST /api/auth/forgot-password
     * Body: { email }
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        // Respons generik 200 OK untuk mencegah email enumeration.
        // Attacker tidak bisa membedakan apakah email terdaftar atau tidak.
        $genericResponse = response()->json([
            'message' => 'Jika email terdaftar, kode OTP akan segera dikirimkan.',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !$user->password) {
            return $genericResponse;
        }

        $otp = OtpCode::generateFor($request->email, 'reset', 5);

        try {
            Mail::to($request->email)->send(new SendOtpMail($otp, 'reset'));
        } catch (\Exception $e) {
            Log::error('Mail Error (Forgot Password): ' . $e->getMessage());
        }

        return $genericResponse;
    }

    /**
     * POST /api/auth/reset-password
     * Body: { email, otp, password, password_confirmation }
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'otp'      => 'required|string|size:6',
            'password' => 'required|min:8|confirmed',
        ]);

        $otpRecord = OtpCode::verify($request->email, 'reset', $request->otp);

        if (!$otpRecord) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kadaluarsa.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        $user->update(['password' => Hash::make($request->password)]);
        $otpRecord->delete();

        return response()->json([
            'message' => 'Password berhasil diperbarui. Silakan login kembali.',
        ]);
    }
}
