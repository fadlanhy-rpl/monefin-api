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

            $frontendUrl = config('services.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));

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

            // Normal flow: langsung buat token dengan info device
            $token = $this->authTokenService->createToken($user, $request);

            return redirect($frontendUrl . '/auth/callback?token=' . $token);

        } catch (\Exception $e) {
            Log::error('Google Login Error: ' . $e->getMessage());
            $frontendUrl = config('services.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
            return redirect($frontendUrl . '/login?error=' . urlencode('Login dengan Google gagal. Silakan coba lagi.'));
        }
    }
}
