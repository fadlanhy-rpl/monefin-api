<?php
// One-click storage link creator for SkipperHost / cPanel
// Security: Batasi akses publik agar tidak membocorkan path server ke bot/scanner
$secretKey = getenv('APP_KEY') ?: 'monefin_storage_setup_key';
$providedKey = $_GET['key'] ?? '';

if (php_sapi_name() !== 'cli' && (empty($providedKey) || !hash_equals($secretKey, $providedKey))) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain');

$target = dirname(__DIR__) . '/storage/app/public';
$link = __DIR__ . '/storage';

echo "Target: $target\n";
echo "Link: $link\n\n";

if (!file_exists($target)) {
    @mkdir($target, 0775, true);
    echo "Created target directory: $target\n";
}

$profilesDir = $target . '/profiles';
if (!file_exists($profilesDir)) {
    @mkdir($profilesDir, 0775, true);
    echo "Created profiles directory: $profilesDir\n";
}

if (file_exists($link) || is_link($link)) {
    echo "Storage link/directory already exists.\n";
} else {
    if (@symlink($target, $link)) {
        echo "SUCCESS: symlink created successfully!\n";
    } else {
        echo "NOTE: symlink() was not created (Fallback route in routes/web.php will handle serving files automatically).\n";
    }
}
