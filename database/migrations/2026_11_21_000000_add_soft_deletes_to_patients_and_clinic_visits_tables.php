<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('students') && !Schema::hasColumn('students', 'deleted_at')) {
            Schema::table('students', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('staff') && !Schema::hasColumn('staff', 'deleted_at')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('faculties') && !Schema::hasColumn('faculties', 'deleted_at')) {
            Schema::table('faculties', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('clinic_visits') && !Schema::hasColumn('clinic_visits', 'deleted_at')) {
            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('students') && Schema::hasColumn('students', 'deleted_at')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('staff') && Schema::hasColumn('staff', 'deleted_at')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('faculties') && Schema::hasColumn('faculties', 'deleted_at')) {
            Schema::table('faculties', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('clinic_visits') && Schema::hasColumn('clinic_visits', 'deleted_at')) {
            Schema::table('clinic_visits', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
