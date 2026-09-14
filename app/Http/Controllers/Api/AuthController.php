<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SendOtpMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\AuthTokenService;
use App\Services\Auth\OtpSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function __construct(
        protected AuthTokenService $authTokenService,
        protected OtpSecurityService $otpSecurityService
    ) {}

    // =========================================================
    // LOGIN
    // =========================================================

    /**
     * User Login
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email|max:255',
            'password' => 'required',
        ]);

        // Proteksi Brute-Force: 5 percobaan per menit per IP & Email
        $throttleKey = mb_strtolower($request->input('email')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Terlalu banyak percobaan. Silakan coba lagi dalam 1 menit.',
            ], 429);
        }

        $user = User::where('email', $request->email)->first();

        // User Google tidak bisa login manual
        if ($user && !$user->password) {
            return response()->json([
                'message' => 'Akun ini terdaftar menggunakan Google. Silakan login dengan Google.',
            ], 400);
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'message' => 'Email atau password tidak valid.',
            ], 401);
        }

        // Cek verifikasi email
        if (!$user->email_verified_at) {
            return response()->json([
                'message'              => 'Email belum diverifikasi. Silakan cek email Anda.',
                'require_verification' => true,
                'email'                => $user->email,
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        // Cek Two-Factor Authentication
        if ($user->two_factor_enabled) {
            if ($penaltyResponse = $this->otpSecurityService->checkOtpPenalty($user->email)) {
                return $penaltyResponse;
            }

            $otp = OtpCode::generateFor($user->email, '2fa', 5);

            try {
                Mail::to($user->email)->send(new SendOtpMail($otp, '2fa'));
            } catch (\Exception $e) {
                Log::error('Mail Error (2FA): ' . $e->getMessage());
                return response()->json(['message' => 'Gagal mengirim kode 2FA. Silakan coba lagi.'], 500);
            }

            return response()->json([
                'require_2fa' => true,
                'email'       => $user->email,
                'message'     => 'Kode verifikasi dikirim ke email Anda.',
            ]);
        }

        $token = $this->authTokenService->createToken($user, $request);

        return response()->json([
            'data'    => ['user' => $user, 'token' => $token],
            'message' => 'Login berhasil.',
        ]);
    }

    // =========================================================
    // REGISTER
    // =========================================================

    /**
     * User Registration
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => 'required|email|max:255',
            'password' => 'required|min:8',
        ]);

        $existingUser = User::where('email', $request->email)->first();

        if ($existingUser) {
            // Jika user sudah terdaftar DAN sudah diverifikasi → tolak
            if ($existingUser->email_verified_at) {
                return response()->json([
                    'message' => 'Email ini sudah terdaftar dan diverifikasi. Silakan login.',
                    'errors'  => ['email' => ['Email ini sudah terdaftar dan diverifikasi. Silakan login.']],
                ], 422);
            }

            // Akun terdaftar yang belum diverifikasi: JANGAN pernah menimpa
            // password/name dari request registrasi. Kredensial dan nama akun asli tetap dipertahankan.
            // OTP verifikasi baru tetap di-generate dan dikirimkan ke email pemilik sah di bawah.
            $user = $existingUser;
        } else {
            // User baru
            $user = User::create([
                'name'     => strip_tags($request->name),
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);
        }

        // Generate & simpan OTP verifikasi baru (Bcrypt hashed di database)
        $otp = OtpCode::generateFor($user->email, 'verification', 5);

        try {
            Mail::to($user->email)->send(new SendOtpMail($otp, 'verification'));
        } catch (\Exception $e) {
            Log::error('Mail Error (Register): ' . $e->getMessage());
        }

        return response()->json([
            'data'    => [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $existingUser ? strip_tags($request->name) : $user->name,
                    'email' => $user->email,
                ],
            ],
            'message' => 'Registrasi berhasil. Silakan cek email Anda untuk kode verifikasi.',
        ], 201);
    }

    // =========================================================
    // ME
    // =========================================================

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data'    => ['user' => $request->user()],
            'message' => 'Profile fetched successfully.',
        ]);
    }

    // =========================================================
    // LOGOUT
    // =========================================================

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json([
            'data'    => null,
            'message' => 'Logout berhasil.',
        ]);
    }
}
