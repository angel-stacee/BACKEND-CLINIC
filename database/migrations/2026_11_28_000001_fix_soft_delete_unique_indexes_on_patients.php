<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('students', 'deleted_token')) {
            Schema::table('students', function (Blueprint $table) {
                $table->unsignedBigInteger('deleted_token')->default(0)->after('deleted_at');
            });
            DB::table('students')->whereNotNull('deleted_at')->update([
                'deleted_token' => DB::raw('id'),
            ]);
            Schema::table('students', function (Blueprint $table) {
                $table->dropUnique(['student_id']);
                $table->unique(['student_id', 'deleted_token'], 'students_student_id_deleted_token_unique');
            });
        }

        if (! Schema::hasColumn('staff', 'deleted_token')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->unsignedBigInteger('deleted_token')->default(0)->after('deleted_at');
            });
            DB::table('staff')->whereNotNull('deleted_at')->update([
                'deleted_token' => DB::raw('id'),
            ]);
            Schema::table('staff', function (Blueprint $table) {
                $table->dropUnique(['staff_id']);
                $table->unique(['staff_id', 'deleted_token'], 'staff_staff_id_deleted_token_unique');
            });
        }

        if (! Schema::hasColumn('faculties', 'deleted_token')) {
            Schema::table('faculties', function (Blueprint $table) {
                $table->unsignedBigInteger('deleted_token')->default(0)->after('deleted_at');
            });
            DB::table('faculties')->whereNotNull('deleted_at')->update([
                'deleted_token' => DB::raw('id'),
            ]);
            Schema::table('faculties', function (Blueprint $table) {
                $table->dropUnique(['employee_id']);
                $table->unique(['employee_id', 'deleted_token'], 'faculties_employee_id_deleted_token_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('students', 'deleted_token')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropUnique('students_student_id_deleted_token_unique');
                $table->unique('student_id');
                $table->dropColumn('deleted_token');
            });
        }

        if (Schema::hasColumn('staff', 'deleted_token')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->dropUnique('staff_staff_id_deleted_token_unique');
                $table->unique('staff_id');
                $table->dropColumn('deleted_token');
            });
        }

        if (Schema::hasColumn('faculties', 'deleted_token')) {
            Schema::table('faculties', function (Blueprint $table) {
                $table->dropUnique('faculties_employee_id_deleted_token_unique');
                $table->unique('employee_id');
                $table->dropColumn('deleted_token');
            });
        }
    }
};
