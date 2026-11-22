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

        $defaultPasswordHash = Hash::make('password123');

        $users = [
            [
                'name' => 'Clinic Staff',
                'email' => 'staff.clinic.edu@gmail.com',
                'role' => 'Nurse',
                'password' => $defaultPasswordHash,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Angel Stacee',
                'email' => 'admin.clinic.edu@gmail.com',
                'role' => 'Nurse',
                'password' => $defaultPasswordHash,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($users as $userData) {
            $existing = DB::table('users')->where('email', $userData['email'])->first();
            if (! $existing) {
                DB::table('users')->insert($userData);
            }
        }
    }

    public function down(): void
    {
        // Don't remove users on rollback
    }
};
