<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * Definisikan named rate limiters untuk fitur-fitur spesifik.
     *
     * Prinsip: per-user (by user ID), bukan per-IP — agar satu user heavy-user
     * tidak mempengaruhi user lain, dan tidak bisa bypass dengan ganti IP.
     */
    private function configureRateLimiters(): void
    {
        // AI Chat — paling berat (memanggil AI provider), dibatasi ketat
        // 5 request/menit per user
        RateLimiter::for('ai-chat', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(5)->by('ai-chat:' . $request->user()->id)
                    ->response(fn () => response()->json([
                        'error'   => true,
                        'message' => 'Terlalu banyak pesan AI. Tunggu sebentar sebelum mengirim pesan berikutnya.',
                        'code'    => 'AI_RATE_LIMITED',
                    ], 429))
                : Limit::perMinute(0); // Tidak diizinkan tanpa auth
        });

        // AI Suggest Category — lebih ringan (bisa fallback ke keyword matching)
        // 15 request/menit per user
        RateLimiter::for('ai-suggest', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(15)->by('ai-suggest:' . $request->user()->id)
                    ->response(fn () => response()->json([
                        'error'   => true,
                        'message' => 'Terlalu banyak request saran kategori. Coba lagi sebentar.',
                        'code'    => 'AI_RATE_LIMITED',
                    ], 429))
                : Limit::perMinute(0);
        });

        // AI Connection Test — hanya untuk setup, dibatasi sangat ketat
        // 3 request per 10 menit per user (cukup untuk test koneksi berulang saat setup)
        RateLimiter::for('ai-connection-test', function (Request $request) {
            return $request->user()
                ? Limit::perMinutes(10, 3)->by('ai-test:' . $request->user()->id)
                    ->response(fn () => response()->json([
                        'ok'      => false,
                        'message' => 'Terlalu banyak percobaan test koneksi. Tunggu beberapa menit.',
                        'code'    => 'RATE_LIMITED',
                    ], 429))
                : Limit::perMinute(0);
        });
    }
}
