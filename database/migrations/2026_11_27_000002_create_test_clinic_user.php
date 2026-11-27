<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $email = 'test.clinic.edu@gmail.com';

        if (DB::table('users')->where('email', $email)->exists()) {
            return;
        }

        $now = now();

        DB::table('users')->insert([
            'name' => 'Test Clinic Account',
            'email' => $email,
            'role' => 'Nurse',
            'password' => Hash::make('password123'),
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Preserve test data when rolling back migrations.
    }
};
