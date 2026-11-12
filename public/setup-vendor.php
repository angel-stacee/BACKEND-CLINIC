<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300');

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $zip = new ZipArchive();
    $res = $zip->open($_FILES['file']['tmp_name']);
    if ($res === TRUE) {
        $zip->extractTo(__DIR__ . '/../');
        $zip->close();
        
        $msg = "vendor.zip extracted successfully!\n";
        
        if (file_exists($vendorAutoload)) {
            require $vendorAutoload;
            $app = require_once __DIR__ . '/../bootstrap/app.php';
            try {
                \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
                $msg .= "Database migrated successfully!\n" . \Illuminate\Support\Facades\Artisan::output();
            } catch (\Throwable $e) {
                $msg .= "Database migration error: " . $e->getMessage() . "\n";
            }
        }
        
        echo "<!DOCTYPE html><html><body style='font-family:sans-serif;background:#0f172a;color:#f8fafc;padding:2rem;'>";
        echo "<div style='background:#1e293b;padding:2rem;border-radius:1rem;max-width:600px;margin:auto;'>";
        echo "<h2 style='color:#4ade80;'>Setup Complete!</h2>";
        echo "<pre style='background:#0f172a;padding:1rem;border-radius:0.5rem;color:#e2e8f0;white-space:pre-wrap;'>" . htmlspecialchars($msg) . "</pre>";
        echo "<p><a href='https://tcc-clinic.cloud/login' style='display:inline-block;background:#3b82f6;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:bold;'>Go to Login Page &rarr;</a></p>";
        echo "</div></body></html>";
        exit;
    } else {
        echo "<pre style='color:red;'>Failed to extract zip file. Error code: $res</pre>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>TCC Clinic - Backend Setup</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2rem; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.5); max-width: 480px; width: 100%; }
        h1 { font-size: 1.25rem; margin-bottom: 1rem; color: #38bdf8; }
        input[type="file"] { display: block; width: 100%; margin: 1rem 0; padding: 0.5rem; background: #334155; border-radius: 0.5rem; color: #fff; box-sizing: border-box; }
        button { background: #0284c7; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; cursor: pointer; width: 100%; font-size: 1rem; }
        button:hover { background: #0369a1; }
        .info { font-size: 0.85rem; color: #94a3b8; margin-top: 1rem; line-height: 1.4; }
        code { color: #38bdf8; background: #0f172a; padding: 0.2rem 0.4rem; border-radius: 0.25rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>TCC Clinic - Backend Setup</h1>
        <p style="font-size: 0.9rem; color: #cbd5e1;">Select the <code>vendor.zip</code> file from your computer below, then click Upload. It will automatically extract your dependencies and run the database migrations.</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="file" accept=".zip" required>
            <button type="submit">Upload & Setup Backend</button>
        </form>
        <div class="info">
            File path on your computer:<br>
            <code>C:\xampp\htdocs\TCC-_CLINIC\backend\vendor.zip</code>
        </div>
    </div>
</body>
</html>
