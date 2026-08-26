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
        // Hapus statefulApi() karena kita menggunakan murni Bearer token (Stateless)
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
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
            }
        });
    })->create();
