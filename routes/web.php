<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'online',
        'service' => 'TCC Clinic Management System API',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::get('/login', function () {
    $frontendUrl = rtrim((string) config('services.frontend.url', 'http://localhost:5173'), '/');

    return redirect()->away($frontendUrl . '/login');
})->name('login');
