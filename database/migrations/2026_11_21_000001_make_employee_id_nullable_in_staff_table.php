<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `staff` MODIFY COLUMN `employee_id` VARCHAR(255) NULL DEFAULT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `staff` MODIFY COLUMN `employee_id` VARCHAR(255) NOT NULL");
    }
};
