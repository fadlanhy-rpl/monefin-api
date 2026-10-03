<?php
/**
 * MoneFin - Image Proxy Server
 * Melayani file gambar dari storage/app/public/ tanpa symlink
 *
 * URL: https://sk0010uoic.skipper.my.id/img.php?f=profiles/filename.jpg
 * Upload ke: monefin-backend/public/img.php
 */

// Security: hanya izinkan path yang valid (tidak ada traversal)
$f = $_GET['f'] ?? '';

if (empty($f) || strpos($f, '..') !== false || strpos($f, '/') === 0) {
    http_response_code(400);
    exit('Invalid request');
}

// Hanya izinkan folder profiles/
if (!preg_match('#^profiles/[a-zA-Z0-9_\-\.]+\.(jpg|jpeg|png|gif|webp)$#i', $f)) {
    http_response_code(403);
    exit('Forbidden');
}

// Cek di public/uploads/profiles/ dulu (upload baru)
$uploadPath = __DIR__ . '/uploads/' . $f;
if (file_exists($uploadPath) && is_file($uploadPath)) {
    serveFile($uploadPath);
    exit;
}

// Fallback ke storage/app/public/ (upload lama)
$storagePath = dirname(__DIR__) . '/storage/app/public/' . $f;
if (file_exists($storagePath) && is_file($storagePath)) {
    serveFile($storagePath);
    exit;
}

http_response_code(404);
exit('Not found');

function serveFile($path) {
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ][$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800'); // 7 hari cache
    header('Content-Length: ' . filesize($path));
    readfile($path);
}
