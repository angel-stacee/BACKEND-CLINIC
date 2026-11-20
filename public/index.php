<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (!file_exists(__DIR__ . '/../.env') && file_exists(__DIR__ . '/../.env.example')) {
    @copy(__DIR__ . '/../.env.example', __DIR__ . '/../.env');
}

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Auto-extract vendor.zip if vendor/autoload.php is missing
$zipCandidates = [
    __DIR__ . '/../vendor.zip',
    __DIR__ . '/vendor.zip',
    dirname(__DIR__) . '/vendor.zip',
];
$targetVendor = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($targetVendor)) {
    foreach ($zipCandidates as $zipCandidate) {
        if (file_exists($zipCandidate) && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipCandidate) === true) {
                $zip->extractTo(dirname($zipCandidate));
                $zip->close();
                break;
            }
        }
    }
}

$vendorPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../vendor/vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/vendor/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
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
    // Attempt automatic self-heal by downloading vendor.zip from cloud storage
    $directZipUrl = 'https://tmpfiles.org/dl/1795192808.0960dc5a1704e50b/wDAWbTf3Yple/vendor.zip';
    $zipTarget = dirname(__DIR__) . '/vendor.zip';
    @ini_set('max_execution_time', '300');
    @ini_set('memory_limit', '512M');
    
    $fp = @fopen($zipTarget, 'w+');
    if ($fp && function_exists('curl_init')) {
        $ch = curl_init($directZipUrl);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    }
    if ((!file_exists($zipTarget) || filesize($zipTarget) < 10000000)) {
        @copy($directZipUrl, $zipTarget);
    }
    
    if (file_exists($zipTarget) && filesize($zipTarget) > 10000000 && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipTarget) === true) {
            $zip->extractTo(dirname(__DIR__));
            $zip->close();
            @unlink($zipTarget);
            foreach ($vendorPaths as $path) {
                if (file_exists($path)) {
                    require $path;
                    $loaded = true;
                    break;
                }
            }
        }
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
