<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SendOtpMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\AuthTokenService;
use App\Services\Auth\OtpSecurityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function __construct(
        protected AuthTokenService $authTokenService,
        protected OtpSecurityService $otpSecurityService
    ) {}

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

            // Batasi HTTP ke Google (token exchange + userinfo): timeout 12 dtk,
            // connect 5 dtk. Tanpa ini, outbound lambat di shared hosting bisa
            // menggantung puluhan detik (default Guzzle).
            $driver->setHttpClient(new \GuzzleHttp\Client([
                'timeout'         => 12,
                'connect_timeout' => 5,
                'verify'          => !app()->environment('local'),
            ]));

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

            $frontendUrl = $this->frontendUrl();

            // Jika user mengaktifkan 2FA, kirim OTP dan redirect ke halaman verifikasi
            if ($user->two_factor_enabled) {
                if ($penaltyResponse = $this->otpSecurityService->checkOtpPenalty($user->email)) {
                    $errorMsg = json_decode($penaltyResponse->getContent())->message;
                    return redirect(
                        $frontendUrl . '/login?error=' . urlencode($errorMsg)
                    );
                }

                $otp = OtpCode::generateFor($user->email, '2fa', 5);

                try {
                    Mail::to($user->email)->send(new SendOtpMail($otp, '2fa'));
                } catch (\Exception $e) {
                    Log::error('Mail Error (Google 2FA): ' . $e->getMessage());
                }

                return redirect(
                    $frontendUrl . '/verify-2fa?email=' . urlencode($user->email)
                );
            }

            // Normal flow: langsung buat token dengan info device + kirim snapshot user
            // agar halaman /auth/callback dapat menghidrasi AuthContext secara instan (0ms)
            // tanpa terjebak layar putih saat menunggu TTFB shared hosting.
            $token = $this->authTokenService->createToken($user, $request);
            $userPayload = rtrim(strtr(base64_encode(json_encode($user)), '+/', '-_'), '=');

            return redirect(
                $frontendUrl . '/auth/callback?token=' . urlencode($token) . '&user=' . urlencode($userPayload)
            );

        } catch (\Exception $e) {
            Log::error('Google Login Error: ' . $e->getMessage());
            $frontendUrl = $this->frontendUrl();
            return redirect($frontendUrl . '/login?error=' . urlencode('Login dengan Google gagal. Silakan coba lagi.'));
        }
    }

    /**
     * URL frontend publik untuk redirect OAuth.
     *
     * Wajib via config (bukan env() langsung): setelah config:cache, env()
     * runtime selalu null sehingga fallback lama jatuh ke localhost:3000.
     * Pengaman: di production / host publik, URL localhost tidak pernah diizinkan keluar.
     */
    private function frontendUrl(): string
    {
        $url = rtrim((string) config('services.frontend_url', 'http://localhost:3000'), '/');
        $host = request()->getHost();
        $isPublicHost = !in_array($host, ['localhost', '127.0.0.1'], true);

        if (($isPublicHost || app()->environment('production')) && ($url === '' || str_contains($url, 'localhost') || str_contains($url, '127.0.0.1'))) {
            Log::warning('FRONTEND_URL fallback ke production (config frontend_url menunjuk localhost di host publik)');
            $url = 'https://www.monefin.web.id';
        }

        return $url;
    }
}
