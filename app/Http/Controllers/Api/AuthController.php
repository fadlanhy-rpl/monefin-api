<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SendOtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
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

        $token = $user->createToken('auth_token')->plainTextToken;

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
            'email'    => 'required|email|max:255|unique:users',
            'password' => 'required|min:6',
        ]);

        $user = User::create([
            'name'     => strip_tags($request->name),
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Generate & Kirim OTP verifikasi
        $otp = rand(100000, 999999);
        OtpCode::create([
            'email'      => $user->email,
            'code'       => $otp,
            'type'       => 'verification',
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        try {
            Mail::to($user->email)->send(new SendOtpMail($otp, 'verification'));
        } catch (\Exception $e) {
            Log::error('Mail Error (Register): ' . $e->getMessage());
        }

        return response()->json([
            'data'    => ['user' => $user],
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

    // =========================================================
    // GOOGLE OAUTH
    // =========================================================

    /**
     * Redirect ke halaman login Google
     * GET /api/auth/google
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * Handle callback dari Google
     * GET /api/auth/google/callback
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                $user = User::create([
                    'name'              => $googleUser->getName(),
                    'email'             => $googleUser->getEmail(),
                    'password'          => null,
                    'google_id'         => $googleUser->getId(),
                    'provider'          => 'google',
                    'email_verified_at' => Carbon::now(),
                ]);
            } else {
                if (!$user->google_id) {
                    $user->update([
                        'google_id'         => $googleUser->getId(),
                        'provider'          => 'google',
                        'email_verified_at' => $user->email_verified_at ?? Carbon::now(),
                    ]);
                }
            }

            $token       = $user->createToken('auth_token')->plainTextToken;
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

            return redirect($frontendUrl . '/auth/callback?token=' . $token);

        } catch (\Exception $e) {
            Log::error('Google Login Error: ' . $e->getMessage());
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect($frontendUrl . '/login?error=' . urlencode('Login dengan Google gagal. Silakan coba lagi.'));
        }
    }

    // =========================================================
    // UPDATE PROFILE
    // =========================================================

    /**
     * POST /api/auth/profile
     * Body (multipart/form-data): { name, photo? }
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name'  => ['required', 'string', 'max:255'],
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ], [
            'photo.image' => 'File harus berupa gambar.',
            'photo.mimes' => 'Format gambar harus jpeg, png, jpg, gif, atau webp.',
            'photo.max'   => 'Ukuran gambar maksimal adalah 2MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $data = ['name' => strip_tags($request->name)];

            if ($request->hasFile('photo')) {
                $file = $request->file('photo');

                if (!$file->isValid()) {
                    return response()->json(['message' => 'Upload foto gagal.'], 400);
                }

                // Hapus foto lama
                if ($user->photo && Storage::disk('public')->exists($user->photo)) {
                    Storage::disk('public')->delete($user->photo);
                }

                $data['photo'] = $file->store('profiles', 'public');
            }

            $user->update($data);

            return response()->json([
                'data'    => ['user' => $user->fresh()],
                'message' => 'Profil berhasil diperbarui.',
            ]);

        } catch (\Exception $e) {
            Log::error('Update Profile Error: ' . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan pada server.'], 500);
        }
    }

    // =========================================================
    // UPDATE PASSWORD
    // =========================================================

    /**
     * POST /api/auth/password
     * Body: { current_password?, new_password, new_password_confirmation }
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => $user->password ? 'required' : 'nullable',
            'new_password'     => 'required|min:8|confirmed',
        ]);

        if ($user->password && !Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini tidak cocok.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return response()->json(['message' => 'Password berhasil diperbarui.']);
    }

    // =========================================================
    // VERIFY EMAIL (OTP)
    // =========================================================

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

        $otpRecord = OtpCode::where('email', $request->email)
            ->where('code', $request->otp)
            ->where('type', 'verification')
            ->where('expires_at', '>', Carbon::now())
            ->first();

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
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'data'    => ['user' => $user->fresh(), 'token' => $token],
            'message' => 'Email berhasil diverifikasi. Selamat datang!',
        ]);
    }

    // =========================================================
    // RESEND OTP
    // =========================================================

    /**
     * POST /api/auth/resend-otp
     * Body: { email, type }
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'type'  => 'required|in:verification,reset',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        OtpCode::where('email', $request->email)->where('type', $request->type)->delete();

        $otp = rand(100000, 999999);
        OtpCode::create([
            'email'      => $request->email,
            'code'       => $otp,
            'type'       => $request->type,
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        try {
            Mail::to($request->email)->send(new SendOtpMail($otp, $request->type));
            return response()->json(['message' => 'Kode OTP baru telah dikirim ke email Anda.']);
        } catch (\Exception $e) {
            Log::error('Mail Error (Resend OTP): ' . $e->getMessage());
            return response()->json(['message' => 'Gagal mengirim email. Silakan coba lagi nanti.'], 500);
        }
    }

    // =========================================================
    // FORGOT PASSWORD
    // =========================================================

    /**
     * POST /api/auth/forgot-password
     * Body: { email }
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Email tidak terdaftar.'], 404);
        }

        if (!$user->password) {
            return response()->json([
                'message' => 'Akun ini terdaftar menggunakan Google dan belum memiliki password. Silakan login dengan Google dan atur password di Pengaturan.',
            ], 400);
        }

        OtpCode::where('email', $request->email)->where('type', 'reset')->delete();

        $otp = rand(100000, 999999);
        OtpCode::create([
            'email'      => $request->email,
            'code'       => $otp,
            'type'       => 'reset',
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        try {
            Mail::to($request->email)->send(new SendOtpMail($otp, 'reset'));
            return response()->json(['message' => 'Kode OTP reset password telah dikirim ke email Anda.']);
        } catch (\Exception $e) {
            Log::error('Mail Error (Forgot Password): ' . $e->getMessage());
            return response()->json(['message' => 'Gagal mengirim email.'], 500);
        }
    }

    // =========================================================
    // RESET PASSWORD
    // =========================================================

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

        $otpRecord = OtpCode::where('email', $request->email)
            ->where('code', $request->otp)
            ->where('type', 'reset')
            ->where('expires_at', '>', Carbon::now())
            ->first();

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
