<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Faculty ---
        if (! Schema::hasColumn('faculties', 'qr_token')) {
            Schema::table('faculties', function (Blueprint $table) {
                $table->string('qr_token', 64)->nullable()->unique()->after('address');
            });
        }

        // Backfill existing faculty rows
        DB::table('faculties')->whereNull('qr_token')->orderBy('id')->each(function ($row) {
            DB::table('faculties')
                ->where('id', $row->id)
                ->update(['qr_token' => 'TCMS-QR-' . bin2hex(random_bytes(12))]);
        });

        // --- Staff ---
        if (! Schema::hasColumn('staff', 'qr_token')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->string('qr_token', 64)->nullable()->unique()->after('address');
            });
        }

        // Backfill existing staff rows
        DB::table('staff')->whereNull('qr_token')->orderBy('id')->each(function ($row) {
            DB::table('staff')
                ->where('id', $row->id)
                ->update(['qr_token' => 'TCMS-QR-' . bin2hex(random_bytes(12))]);
        });
    }

    public function down(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->dropColumn('qr_token');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('qr_token');
        });
    }
};
