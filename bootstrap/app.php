<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Jangan redirect guest ke route('login') yang tidak ada pada API stateless
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        // Security headers untuk semua API response
        $middleware->appendToGroup('api', \App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true,
        );

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            $token = $request->bearerToken();
            if ($token) {
                $tokenParts = explode('|', $token, 2);
                $plainTextToken = count($tokenParts) === 2 ? $tokenParts[1] : $tokenParts[0];
                $hashed = hash('sha256', $plainTextToken);
                
                $revokedDetails = \Illuminate\Support\Facades\Cache::get('revoked_' . $hashed);
                if ($revokedDetails) {
                    return response()->json([
                        'message' => 'Unauthenticated.',
                        'revoked_by' => $revokedDetails
                    ], 401);
                }
            }

            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        });
    })->create();
