<?php
header('Content-Type: text/plain; charset=utf-8');
$url = 'https://raw.githubusercontent.com/fadlanhy-rpl/monefin-api/feature/hybrid-deployment-environment/public/patch_ai_byok.php';
$target = __DIR__ . '/patch_ai_byok.php';
$content = @file_get_contents($url);
if (!$content) {
    die("Failed to fetch from GitHub: $url\n");
}
file_put_contents($target, $content);
echo "Fetched latest patch_ai_byok.php from GitHub (" . strlen($content) . " bytes).\nExecuting patch...\n\n";
include $target;
