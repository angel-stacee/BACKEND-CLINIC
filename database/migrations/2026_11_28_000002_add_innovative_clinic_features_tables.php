<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Follow-up Monitoring columns on clinic_visits
        Schema::table('clinic_visits', function (Blueprint $table) {
            if (! Schema::hasColumn('clinic_visits', 'follow_up_date')) {
                $table->date('follow_up_date')->nullable()->after('remarks');
            }
            if (! Schema::hasColumn('clinic_visits', 'follow_up_status')) {
                $table->string('follow_up_status', 30)->nullable()->after('follow_up_date');
            }
            if (! Schema::hasColumn('clinic_visits', 'follow_up_notes')) {
                $table->text('follow_up_notes')->nullable()->after('follow_up_status');
            }
            $table->index(['follow_up_date', 'follow_up_status'], 'clinic_visits_follow_up_idx');
        });

        // 2. Audit Logs table (immutable activity timeline)
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('user_name')->nullable();
                $table->string('user_role', 50)->nullable();
                $table->string('action', 100)->index();
                $table->string('subject_type', 100)->nullable()->index();
                $table->string('subject_id', 100)->nullable();
                $table->string('description', 500);
                $table->string('ip_address', 45)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        // 3. Digital Clinic Queue table
        if (! Schema::hasTable('clinic_queues')) {
            Schema::create('clinic_queues', function (Blueprint $table) {
                $table->id();
                $table->string('patient_type', 20); // student, faculty, staff
                $table->unsignedBigInteger('patient_id');
                $table->string('patient_identifier', 100)->nullable();
                $table->string('patient_name');
                $table->string('department')->nullable();
                $table->string('priority', 20)->default('Routine'); // Routine, Urgent, Follow-up
                $table->string('status', 30)->default('Waiting'); // Waiting, In Consultation, Completed, Cancelled
                $table->string('chief_complaint', 255)->nullable();
                $table->foreignId('clinic_visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('checked_in_at')->useCurrent();
                $table->timestamp('called_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'priority', 'checked_in_at'], 'clinic_queues_active_idx');
                $table->index(['patient_type', 'patient_id'], 'clinic_queues_patient_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_queues');
        Schema::dropIfExists('audit_logs');

        Schema::table('clinic_visits', function (Blueprint $table) {
            $table->dropIndex('clinic_visits_follow_up_idx');
            $cols = array_filter([
                Schema::hasColumn('clinic_visits', 'follow_up_date') ? 'follow_up_date' : null,
                Schema::hasColumn('clinic_visits', 'follow_up_status') ? 'follow_up_status' : null,
                Schema::hasColumn('clinic_visits', 'follow_up_notes') ? 'follow_up_notes' : null,
            ]);
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
