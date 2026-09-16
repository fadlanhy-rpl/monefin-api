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
