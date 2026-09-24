<?php
/**
 * MoneFin - Patch Sesi Login & Deteksi Device (Android + Real IP)
 *
 * Upload ke: monefin-backend/public/patch_fix.php
 * Akses via: https://sk0010uoic.skipper.my.id/patch_fix.php
 * HAPUS file ini setelah digunakan!
 */

header('Content-Type: text/plain; charset=utf-8');

$base = dirname(__DIR__);
echo "=== MoneFin Patch Sesi Login & Device ===\n\n";

// ── 1. Update DeviceDetectorService.php Secara Utuh ───────────────
$devDetectorFile = $base . '/app/Services/Auth/DeviceDetectorService.php';

$fullDeviceDetectorCode = <<<'PHP'
<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;

class DeviceDetectorService
{
    /**
     * Mendeteksi format label perangkat, browser, dan sistem operasi.
     * Contoh: "Chrome on Windows (Desktop)"
     */
    public function detectDevice(Request $request): string
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
        } elseif (str_contains($ua, 'MiuiBrowser') || str_contains($ua, 'XiaoMi/MiuiBrowser')) {
            $browser = 'Mi Browser';
        } elseif (str_contains($ua, 'VivoBrowser')) {
            $browser = 'Vivo Browser';
        } elseif (str_contains($ua, 'HeyTapBrowser')) {
            $browser = 'Oppo Browser';
        } elseif (str_contains($ua, 'UCBrowser') || str_contains($ua, 'UBrowser')) {
            $browser = 'UC Browser';
        } elseif (str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome')) {
            $browser = 'Safari';
        } else {
            $browser = 'Browser';
        }

        // Urutan deteksi OS:
        // PENTING: Android HARUS dicek sebelum Linux, karena UA Android selalu mengandung "Linux; Android"
        if (str_contains($ua, 'Windows'))        $os = 'Windows';
        elseif (str_contains($ua, 'Android'))    $os = 'Android';
        elseif (str_contains($ua, 'iPhone'))     $os = 'iPhone';
        elseif (str_contains($ua, 'iPad'))       $os = 'iPad';
        elseif (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X')) $os = 'Mac';
        elseif (str_contains($ua, 'Linux'))      $os = 'Linux';
        else                                     $os = 'Unknown OS';

        return "{$browser} on {$os} ({$device})";
    }
}
PHP;

if (file_put_contents($devDetectorFile, $fullDeviceDetectorCode) !== false) {
    echo "SUCCESS: DeviceDetectorService.php berhasil diperbarui secara penuh!\n";
} else {
    echo "FAIL: Tidak dapat menulis ke $devDetectorFile (cek permission)\n";
}

// ── 2. Verifikasi bootstrap/app.php (Real IP) ──────────────────────
$bootstrapFile = $base . '/bootstrap/app.php';
if (file_exists($bootstrapFile)) {
    $bootCode = file_get_contents($bootstrapFile);
    if (strpos($bootCode, 'trustProxies') === false) {
        $target = "->withMiddleware(function (Middleware \$middleware): void {\n";
        $replacement = "->withMiddleware(function (Middleware \$middleware): void {\n        // Percayai reverse proxy agar IP asli client terbaca\n        \$middleware->trustProxies(at: '*');\n";
        $bootCode = str_replace($target, $replacement, $bootCode);
        file_put_contents($bootstrapFile, $bootCode);
        echo "SUCCESS: bootstrap/app.php dipatch (trustProxies aktif)\n";
    } else {
        echo "OK: bootstrap/app.php sudah memiliki trustProxies\n";
    }
}

// ── 3. Update juga personal_access_tokens lama yang berlabel Linux jika ada ───
try {
    $pdo = new PDO("mysql:host=localhost;dbname=u_sk0010uoic;charset=utf8", "u_sk0010uoic", "rf5irO93idDE", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    // Ganti sesi yang sudah terlanjur berlabel Linux (Mobile) menjadi Android (Mobile)
    $stmt = $pdo->exec("UPDATE personal_access_tokens SET name = REPLACE(name, 'Linux (Mobile)', 'Android (Mobile)'), device_name = REPLACE(device_name, 'Linux (Mobile)', 'Android (Mobile)') WHERE name LIKE '%Linux (Mobile)%' OR device_name LIKE '%Linux (Mobile)%'");
    echo "DATABASE: Berhasil memperbarui $stmt sesi lama dari 'Linux (Mobile)' menjadi 'Android (Mobile)'!\n";
} catch (Exception $e) {
    echo "DATABASE NOTE: " . $e->getMessage() . "\n";
}

// ── 4. Tes Langsung Deteksi Browser Saat Ini ───────────────────────
echo "\n=== Hasil Tes Deteksi Browser ===\n";
$currentUa = $_SERVER['HTTP_USER_AGENT'] ?? '(kosong)';
echo "User-Agent Anda : " . $currentUa . "\n";

require_once $devDetectorFile;
$dummyReq = \Illuminate\Http\Request::createFromGlobals();
$detector = new \App\Services\Auth\DeviceDetectorService();
echo "Hasil Deteksi   : " . $detector->detectDevice($dummyReq) . "\n";
echo "IP Terbaca      : " . ($dummyReq->ip() ?? $_SERVER['REMOTE_ADDR'] ?? 'tidak diketahui') . "\n";

echo "\n=== SELESAI ===\n";
echo "Sekarang silakan buka menu Settings MoneFin di browser Anda.\n";
