<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewLoginAlertMail;
use App\Mail\SendOtpMail;
use App\Mail\SuspiciousLoginMail;
use App\Models\OtpCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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

        // Cek Two-Factor Authentication
        if ($user->two_factor_enabled) {
            if ($penaltyResponse = $this->checkOtpPenalty($user->email)) {
                return $penaltyResponse;
            }

            OtpCode::where('email', $user->email)->where('type', '2fa')->delete();
            $otp = rand(100000, 999999);
            OtpCode::create([
                'email'      => $user->email,
                'code'       => $otp,
                'type'       => '2fa',
                'expires_at' => Carbon::now()->addMinutes(5),
            ]);

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

        $token = $this->createToken($user, $request);

        return response()->json([
            'data'    => ['user' => $user, 'token' => $token],
            'message' => 'Login berhasil.',
        ]);
    }

    /**
     * Helper: buat Sanctum token dengan info device
     * Update menggunakan ID token langsung agar tidak salah target.
     */
    private function createToken(User $user, Request $request): string
    {
        $deviceName  = $this->detectDevice($request);
        
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

    private function detectDevice(Request $request): string
    {
        $ua = $request->userAgent() ?? '';
        $clientBrowser = $request->header('X-Client-Browser') 
            ?? $request->header('x-client-browser') 
            ?? $request->cookie('client_browser') 
            ?? $request->query('client_browser');

        if (str_contains($ua, 'Mobile') || str_contains($ua, 'Android') || str_contains($ua, 'iPhone')) {
            $device = 'Mobile';
        } else {
            $device = 'Desktop';
        }

        // Urutan deteksi browser:
        // 1. Cek header/cookie client kustom (Brave secara sengaja menyamarkan UA menjadi Chrome untuk privasi)
        if ($clientBrowser && strcasecmp($clientBrowser, 'Brave') === 0) {
            $browser = 'Brave';
        } elseif (str_contains($ua, 'Brave')) {
            $browser = 'Brave';
        } elseif (str_contains($ua, 'Edg')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'OPR') || str_contains($ua, 'Opera')) {
            $browser = 'Opera';
        } elseif (str_contains($ua, 'Vivaldi')) {
            $browser = 'Vivaldi';
        } elseif (str_contains($ua, 'SamsungBrowser')) {
            $browser = 'Samsung Internet';
        } elseif (str_contains($ua, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome')) {
            $browser = 'Safari';
        } else {
            $browser = 'Browser';
        }

        if (str_contains($ua, 'Windows'))        $os = 'Windows';
        elseif (str_contains($ua, 'Macintosh'))  $os = 'Mac';
        elseif (str_contains($ua, 'Linux'))      $os = 'Linux';
        elseif (str_contains($ua, 'Android'))    $os = 'Android';
        elseif (str_contains($ua, 'iPhone'))     $os = 'iPhone';
        else                                     $os = 'Unknown OS';

        return "{$browser} on {$os} ({$device})";
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
            'password' => 'required|min:6',
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

            // Jika user sudah terdaftar tapi BELUM diverifikasi → perbarui data & buat OTP baru
            $existingUser->update([
                'name'     => strip_tags($request->name),
                'password' => Hash::make($request->password),
            ]);
            $user = $existingUser;
        } else {
            // User baru
            $user = User::create([
                'name'     => strip_tags($request->name),
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);
        }

        // Hapus OTP verifikasi lama untuk email ini
        OtpCode::where('email', $user->email)->where('type', 'verification')->delete();

        // Generate & Kirim OTP verifikasi baru
        $otp = rand(100000, 999999);
        OtpCode::create([
            'email'      => $user->email,
            'code'       => $otp,
            'type'       => 'verification',
            'expires_at' => Carbon::now()->addMinutes(5),
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
    public function redirectToGoogle(Request $request)
    {
        /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
        $driver = Socialite::driver('google');

        if (app()->environment('local')) {
            $driver->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
        }

        $redirect = $driver->stateless()->redirect();

        if ($browser = $request->query('client_browser')) {
            $redirect->withCookie(cookie('client_browser', $browser, 10));
        }

        return $redirect;
    }

    /**
     * Handle callback dari Google
     * GET /api/auth/google/callback
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
            $driver = Socialite::driver('google');

            if (app()->environment('local')) {
                $driver->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
            }

            $googleUser = $driver->stateless()->user();

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

            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

            // Jika user mengaktifkan 2FA, kirim OTP dan redirect ke halaman verifikasi
            if ($user->two_factor_enabled) {
                if ($penaltyResponse = $this->checkOtpPenalty($user->email)) {
                    $errorMsg = json_decode($penaltyResponse->getContent())->message;
                    return redirect(
                        $frontendUrl . '/login?error=' . urlencode($errorMsg)
                    );
                }

                OtpCode::where('email', $user->email)->where('type', '2fa')->delete();
                $otp = rand(100000, 999999);
                OtpCode::create([
                    'email'      => $user->email,
                    'code'       => $otp,
                    'type'       => '2fa',
                    'expires_at' => Carbon::now()->addMinutes(5),
                ]);

                try {
                    Mail::to($user->email)->send(new SendOtpMail($otp, '2fa'));
                } catch (\Exception $e) {
                    Log::error('Mail Error (Google 2FA): ' . $e->getMessage());
                }

                return redirect(
                    $frontendUrl . '/verify-2fa?email=' . urlencode($user->email)
                );
            }

            // Normal flow: langsung buat token dengan info device
            $token = $this->createToken($user, $request);

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
            'name'        => ['required', 'string', 'max:255'],
            'photo'       => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'phone'       => 'nullable|string|max:50',
            'occupation'  => 'nullable|string|max:100',
            'bio'         => 'nullable|string',
            'preferences' => 'nullable|string', // Since it might be sent as JSON string in FormData
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
            $data = [
                'name'       => strip_tags($request->name),
                'phone'      => $request->phone ? strip_tags($request->phone) : null,
                'occupation' => $request->occupation ? strip_tags($request->occupation) : null,
                'bio'        => $request->bio ? strip_tags($request->bio) : null,
            ];

            if ($request->has('preferences') && !is_null($request->preferences)) {
                $prefs = json_decode($request->preferences, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data['preferences'] = $prefs;
                }
            }

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
        $token = $this->createToken($user, $request);

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
            'type'  => 'required|in:verification,reset,2fa',
        ]);

        if ($penaltyResponse = $this->checkOtpPenalty($request->email)) {
            return $penaltyResponse;
        }

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
            'expires_at' => Carbon::now()->addMinutes(5),
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
            'expires_at' => Carbon::now()->addMinutes(5),
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

    // =========================================================
    // DELETE ACCOUNT
    // =========================================================

    /**
     * DELETE /api/auth/profile
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Revoke all tokens
        $user->tokens()->delete();
        
        // Delete the user (cascades to all related data)
        $user->delete();

        return response()->json([
            'message' => 'Akun dan seluruh data berhasil dihapus secara permanen.',
        ]);
    }

    // =========================================================
    // TWO-FACTOR AUTHENTICATION
    // =========================================================

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

        if ($penaltyResponse = $this->checkOtpPenalty($request->email)) {
            return $penaltyResponse;
        }

        $otpRecord = OtpCode::where('email', $request->email)
            ->where('code', $request->otp)
            ->where('type', '2fa')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$otpRecord) {
            return $this->handleFailedOtp($request->email);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        $otpRecord->delete();

        // Bersihkan cache counter gagal jika berhasil login
        Cache::forget("otp_fails_{$request->email}");
        Cache::forget("otp_penalty_{$request->email}");

        $token = $this->createToken($user, $request);

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
                $gamification = app(\App\Services\GamificationService::class);
                $gamification->awardXP($user, 75, 'Aktivasi Two-Factor Authentication');
                $gamification->updateAchievementProgress($user, 'security_2fa', 1);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Gamification Error (2FA toggle): ' . $e->getMessage());
            }
        }

        return response()->json([
            'data'    => ['user' => $user->fresh()],
            'message' => $request->enabled
                ? 'Two-Factor Authentication berhasil diaktifkan.'
                : 'Two-Factor Authentication berhasil dinonaktifkan.',
        ]);
    }

    // =========================================================
    // SESSIONS
    // =========================================================

    /**
     * GET /api/auth/sessions
     */
    public function getSessions(Request $request): JsonResponse
    {
        $user         = $request->user();
        $currentToken = $user->currentAccessToken();

        $sessions = $user->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(function ($token) use ($currentToken) {
                // Token lama (sebelum migrasi) memiliki name = 'auth_token' dan device_name = null
                // Tampilkan label yang lebih informatif
                $deviceLabel = $token->device_name;
                if (!$deviceLabel) {
                    $deviceLabel = ($token->name && $token->name !== 'auth_token')
                        ? $token->name
                        : 'Sesi Lama (Browser Tidak Diketahui)';
                }

                return [
                    'id'          => $token->id,
                    'device_name' => $deviceLabel,
                    'ip_address'  => $token->ip_address ?: 'IP tidak tersimpan',
                    'last_used_at'=> $token->last_used_at,
                    'created_at'  => $token->created_at,
                    'is_current'  => $token->id === $currentToken->id,
                    'is_legacy'   => !$token->device_name, // flag untuk token lama
                ];
            });

        return response()->json([
            'data'    => ['sessions' => $sessions],
            'message' => 'Sessions fetched successfully.',
        ]);
    }

    /**
     * DELETE /api/auth/sessions/{tokenId}
     */
    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $user         = $request->user();
        $currentToken = $user->currentAccessToken();

        if ($currentToken->id === $tokenId) {
            return response()->json(['message' => 'Tidak dapat menghapus sesi aktif Anda sendiri.'], 400);
        }

        $tokenToDelete = $user->tokens()->where('id', $tokenId)->first();

        if (!$tokenToDelete) {
            return response()->json(['message' => 'Sesi tidak ditemukan.'], 404);
        }

        // Simpan info siapa yang mencabut token ini menggunakan hash token sebagai key
        $revokerDetails = [
            'device' => $currentToken->device_name ?: 'Perangkat Tidak Diketahui',
            'ip'     => $currentToken->ip_address ?: 'IP tidak tersimpan',
            'time'   => now()->translatedFormat('d M Y, H:i')
        ];
        Cache::put('revoked_' . $tokenToDelete->token, $revokerDetails, now()->addDay());

        $tokenToDelete->delete();

        return response()->json(['message' => 'Sesi berhasil dikeluarkan.']);
    }

    /**
     * DELETE /api/auth/sessions — revoke all OTHER sessions
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user         = $request->user();
        $currentToken = $user->currentAccessToken();

        $tokensToDelete = $user->tokens()->where('id', '!=', $currentToken->id)->get();

        $revokerDetails = [
            'device' => $currentToken->device_name ?: 'Perangkat Tidak Diketahui',
            'ip'     => $currentToken->ip_address ?: 'IP tidak tersimpan',
            'time'   => now()->translatedFormat('d M Y, H:i')
        ];

        foreach ($tokensToDelete as $tokenToDel) {
            Cache::put('revoked_' . $tokenToDel->token, $revokerDetails, now()->addDay());
            $tokenToDel->delete();
        }

        return response()->json(['message' => 'Semua sesi lain berhasil dikeluarkan.']);
    }

    // =========================================================
    // OTP PENALTY HELPERS
    // =========================================================

    /**
     * Mengecek apakah email dikenakan penalty.
     * Jika ya, mengembalikan response JSON error dengan sisa waktu.
     * Jika tidak, mengembalikan null.
     */
    private function checkOtpPenalty(string $email): ?JsonResponse
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
    private function handleFailedOtp(string $email): JsonResponse
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

    // =========================================================
    // SECURE ACCOUNT (ONE-CLICK REVOKE & RESET PASSWORD)
    // =========================================================

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
        OtpCode::where('email', $user->email)->where('type', 'reset')->delete();
        $otp = rand(100000, 999999);
        OtpCode::create([
            'email'      => $user->email,
            'code'       => $otp,
            'type'       => 'reset',
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

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

