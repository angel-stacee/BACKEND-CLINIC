<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('max_execution_time', '300');
ini_set('memory_limit', '512M');

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
$targetDir = dirname(__DIR__);

echo "<!DOCTYPE html><html><body style='font-family:sans-serif; background:#0f172a; color:#f8fafc; padding:2rem;'>";
echo "<div style='background:#1e293b; padding:2rem; border-radius:1rem; max-width:600px; margin:auto;'>";
echo "<h2 style='color:#38bdf8;'>TCC Clinic - Automatic Vendor Installer</h2>";

if (file_exists($vendorAutoload)) {
    echo "<p style='color:#4ade80;'>✓ Laravel <code>vendor/autoload.php</code> is already installed and ready!</p>";
    echo "<p><a href='/' style='display:inline-block; background:#70091f; color:white; padding:0.75rem 1.5rem; border-radius:0.5rem; text-decoration:none; font-weight:bold;'>Go to Website &rarr;</a></p>";
    echo "</div></body></html>";
    exit;
}

$directZipUrl = 'https://tmpfiles.org/dl/1795192808.0960dc5a1704e50b/wDAWbTf3Yple/vendor.zip';
$zipTarget = $targetDir . '/vendor.zip';

echo "<p>Downloading Laravel vendor dependencies (36 MB) directly to server...</p>";
flush();

$fp = @fopen($zipTarget, 'w+');
if ($fp && function_exists('curl_init')) {
    $ch = curl_init($directZipUrl);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);
}

if (!file_exists($zipTarget) || filesize($zipTarget) < 10000000) {
    echo "<p>Trying fallback download stream...</p>";
    flush();
    @copy($directZipUrl, $zipTarget);
}

if (!file_exists($zipTarget) || filesize($zipTarget) < 10000000) {
    echo "<p style='color:red;'>Could not download vendor.zip automatically.</p>";
    echo "<p><a href='/setup-vendor.php' style='color:#38bdf8;'>Click here to use manual upload instead &rarr;</a></p>";
    echo "</div></body></html>";
    exit;
}

echo "<p style='color:#4ade80;'>✓ Download completed (" . round(filesize($zipTarget)/1024/1024, 2) . " MB)</p>";
echo "<p>Extracting packages into <code>" . htmlspecialchars($targetDir) . "/vendor</code>...</p>";
flush();

if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zipTarget) === true) {
        $zip->extractTo($targetDir);
        $zip->close();
        @unlink($zipTarget);
        echo "<h3 style='color:#4ade80;'>✓ SUCCESS! Vendor dependencies extracted successfully!</h3>";
        echo "<p>Laravel <code>vendor/autoload.php</code> is now active.</p>";
        echo "<p><a href='/' style='display:inline-block; background:#70091f; color:white; padding:0.75rem 1.5rem; border-radius:0.5rem; text-decoration:none; font-weight:bold;'>Open Website &rarr;</a></p>";
    } else {
        echo "<p style='color:red;'>Failed to extract vendor.zip.</p>";
    }
} else {
    echo "<p style='color:red;'>ZipArchive PHP extension is not enabled.</p>";
}

echo "</div></body></html>";
