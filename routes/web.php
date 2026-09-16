<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

Route::get('/', function () {
    return view('welcome');
});

// Fallback jika web server / Apache symlink public/storage tidak terhubung di cPanel
Route::get('/storage/{path}', function ($path) {
    $cleanPath = ltrim($path, '/');
    if (!Storage::disk('public')->exists($cleanPath)) {
        abort(404, 'File not found');
    }
    return Storage::disk('public')->response($cleanPath, null, [
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*');
