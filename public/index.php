<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Normalize /index.php/ URI so Laravel routing, Symfony Request, and CORS always match /api/* seamlessly
if (isset($_SERVER['REQUEST_URI'])) {
    if (str_starts_with($_SERVER['REQUEST_URI'], '/index.php/')) {
        $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], 10);
    } elseif ($_SERVER['REQUEST_URI'] === '/index.php') {
        $_SERVER['REQUEST_URI'] = '/';
    }
}
if (!isset($_SERVER['SCRIPT_NAME']) || $_SERVER['SCRIPT_NAME'] === '') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
