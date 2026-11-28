<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('students', 'qr_token')) {
            Schema::table('students', function (Blueprint $table) {
                $table->string('qr_token', 64)->nullable()->unique()->after('student_id');
            });

            // Safely backfill existing students with opaque random QR tokens (zero PII)
            DB::table('students')
                ->whereNull('qr_token')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('students')
                            ->where('id', $row->id)
                            ->update([
                                'qr_token' => 'TCMS-QR-'.bin2hex(random_bytes(12)),
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('students', 'qr_token')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropUnique(['qr_token']);
                $table->dropColumn('qr_token');
            });
        }
    }
};
