<?php
/**
 * MoneFin - Patch Google OAuth Forgot Password UX
 * 
 * Cara Penggunaan di SkipperHost:
 * 1. Upload file ini ke folder: monefin-backend/public/patch_oauth_reset.php
 * 2. Buka di browser: https://sk0010uoic.skipper.my.id/patch_oauth_reset.php
 * 3. Hapus file ini setelah selesai digunakan demi keamanan!
 */

header("Content-Type: text/html; charset=utf-8");

$baseDir = dirname(__DIR__); // monefin-backend root
$targetRel = 'app/Http/Controllers/Api/PasswordResetController.php';
$targetPath = $baseDir . '/' . $targetRel;
$backupPath = $targetPath . '.bak_' . date('Ymd_His');

$code = <<<'PHP'
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

        if (!$user) {
            return $genericResponse;
        }

        // Akun terdaftar via Google OAuth tanpa password lokal.
        // Berikan respons informatif agar user tahu harus login via Google.
        if (!$user->password) {
            return response()->json([
                'message'    => 'Akun ini terdaftar melalui Google. Silakan login dengan Google, lalu atur password di menu Pengaturan > Keamanan.',
                'oauth_only' => true,
            ]);
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
PHP;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MoneFin - Patch Password Reset Controller</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b0f19; color: #f3f4f6; padding: 2rem; margin: 0; }
        .card { max-width: 680px; margin: 0 auto; background: #161e2e; border: 1px solid #374151; border-radius: 16px; padding: 2rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { color: #10b981; font-size: 1.4rem; margin-top: 0; display: flex; align-items: center; gap: 8px; }
        .badge { background: #064e3b; color: #6ee7b7; padding: 3px 8px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; }
        .log-box { background: #030712; border: 1px solid #1f2937; border-radius: 10px; padding: 1.2rem; font-family: monospace; font-size: 0.88rem; line-height: 1.6; margin-top: 1.5rem; }
        .success { color: #34d399; }
        .warn { color: #fbbf24; }
        .error { color: #f87171; }
        .info { color: #60a5fa; }
        .footer-alert { margin-top: 1.5rem; padding: 1rem; background: #451a03; border: 1px solid #b45309; border-radius: 8px; color: #fde68a; font-size: 0.9rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>MoneFin Password Reset Patcher <span class="badge">OAuth UX</span></h1>
    <p style="color: #9ca3af; font-size: 0.92rem; margin-bottom: 0;">
        Patcher otomatis untuk mendukung deteksi akun Google OAuth pada fitur lupa password & pengiriman pesan informatif.
    </p>

    <div class="log-box">
<?php
$errors = 0;
echo "<span class=\"info\">[START] Menyiapkan pembaruan PasswordResetController...</span><br>";

// 1. Backup file lama
if (file_exists($targetPath)) {
    if (@copy($targetPath, $backupPath)) {
        echo "<span class=\"success\">[BACKUP] File lama dicadangkan ke: " . basename($backupPath) . "</span><br>";
    } else {
        echo "<span class=\"warn\">[NOTE] Tidak dapat membuat file backup, melanjutkan penulisan...</span><br>";
    }
}

// 2. Tulis file baru
$bytes = @file_put_contents($targetPath, $code);
if ($bytes !== false) {
    echo "<span class=\"success\">[OK] {$targetRel}</span> (" . number_format($bytes) . " bytes berhasil ditulis)<br>";
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($targetPath, true);
        echo "<span class=\"success\">[OPCACHE] OPcache untuk PasswordResetController berhasil direset.</span><br>";
    }
} else {
    $errors++;
    echo "<span class=\"error\">[FAIL] Gagal menulis ke {$targetPath}. Periksa permission folder.</span><br>";
}

// 3. Bersihkan bootstrap cache
echo "<br><span class=\"info\">[CACHE] Membersihkan bootstrap cache...</span><br>";
$cacheFiles = [
    $baseDir . "/bootstrap/cache/config.php",
    $baseDir . "/bootstrap/cache/services.php",
    $baseDir . "/bootstrap/cache/packages.php",
    $baseDir . "/bootstrap/cache/routes-v7.php",
];

foreach ($cacheFiles as $cf) {
    if (file_exists($cf)) {
        if (@unlink($cf)) {
            echo "<span class=\"success\">[PURGED] " . basename($cf) . "</span><br>";
        } else {
            echo "<span class=\"warn\">[SKIP] Gagal menghapus " . basename($cf) . "</span><br>";
        }
    }
}

echo "<br>";
if ($errors === 0) {
    echo "<span class=\"success\" style=\"font-weight:bold; font-size:1.05rem;\">&#10004; SUKSES! Backend SkipperHost berhasil diperbarui dengan penanganan Google OAuth.</span><br>";
} else {
    echo "<span class=\"error\" style=\"font-weight:bold;\">&#10008; Terjadi kegagalan saat patching file.</span><br>";
}
?>
    </div>

    <div class="footer-alert">
        <strong>PENTING:</strong> Segera <u>HAPUS</u> file <code>patch_oauth_reset.php</code> dari folder <code>monefin-backend/public/</code> setelah patch ini selesai agar tidak disalahgunakan.
    </div>
</div>
</body>
</html>
