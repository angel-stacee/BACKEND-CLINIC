<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $backupPath = database_path('seeders/clinic_backup.sql');
        if (File::exists($backupPath)) {
            $sql = File::get($backupPath);
            DB::unprepared($sql);
        }

        $this->call([
            MedicineSeeder::class,
        ]);
    }
}
