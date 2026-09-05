<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityHeaders
{
    /**
     * Tambahkan security headers ke setiap response API.
     *
     * Headers ini melindungi dari MIME sniffing, clickjacking,
     * XSS, dan kebocoran referrer — defense-in-depth layer
     * yang bekerja di atas perlindungan laravel sendiri.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Skip untuk SSE streaming — header Content-Type sudah di-set manual
        // dan menambahkan header lain bisa memutus koneksi streaming AI.
        if ($response instanceof StreamedResponse) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'"
        );

        return $response;
    }
}
