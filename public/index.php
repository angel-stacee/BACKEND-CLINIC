<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (!file_exists(__DIR__ . '/../.env')) {
    if (file_exists(__DIR__ . '/../.env.example')) {
        @copy(__DIR__ . '/../.env.example', __DIR__ . '/../.env');
    }
    if (file_exists(__DIR__ . '/../.env')) {
        $content = file_get_contents(__DIR__ . '/../.env');
        if (strpos($content, 'APP_KEY=base64') === false) {
            $content = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=base64:iloJwlDVhlO5EYituRhUZIOjkNCrW3saOEqf5W4sNVU=', $content);
            @file_put_contents(__DIR__ . '/../.env', $content);
        }
    } else {
        @file_put_contents(__DIR__ . '/../.env', "APP_NAME=Laravel\nAPP_ENV=production\nAPP_KEY=base64:iloJwlDVhlO5EYituRhUZIOjkNCrW3saOEqf5W4sNVU=\nAPP_DEBUG=true\n");
    }
}

// Ensure framework storage directories exist
@mkdir(__DIR__ . '/../storage/framework/views', 0777, true);
@mkdir(__DIR__ . '/../storage/framework/sessions', 0777, true);
@mkdir(__DIR__ . '/../storage/framework/cache/data', 0777, true);
@mkdir(__DIR__ . '/../storage/logs', 0777, true);

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

$vendorPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../vendor/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/vendor/vendor/autoload.php',
    __DIR__ . '/../backend/vendor/autoload.php',
    __DIR__ . '/../api/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
];

$loaded = false;
foreach ($vendorPaths as $path) {
    if (file_exists($path)) {
        require $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    echo "<h2>Laravel is looking for vendor/autoload.php</h2>";
    echo "<p>None of the expected paths were found:</p><ul>";
    foreach ($vendorPaths as $p) {
        echo "<li><code>" . htmlspecialchars($p) . "</code></li>";
    }
    echo "</ul>";
    echo "<h3>Folder contents of api/ (parent directory):</h3>";
    if (is_dir(__DIR__ . '/..')) {
        echo "<pre>" . htmlspecialchars(print_r(scandir(__DIR__ . '/..'), true)) . "</pre>";
    }
    if (is_dir(__DIR__ . '/../vendor')) {
        echo "<h3>Folder contents of api/vendor/:</h3>";
        echo "<pre>" . htmlspecialchars(print_r(scandir(__DIR__ . '/../vendor'), true)) . "</pre>";
    }
    exit;
}

$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
