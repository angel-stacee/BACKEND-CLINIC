<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained('students')->onDelete('cascade');
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->onDelete('cascade');
            $table->foreignId('staff_id')->nullable()->constrained('staff')->onDelete('cascade');
            $table->string('patient_type', 30)->default('student'); // student, faculty, staff
            $table->string('patient_name');
            $table->foreignId('medicine_id')->constrained('medicines')->onDelete('cascade');
            $table->foreignId('nurse_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('dosage', 100); // e.g. "1 Tablet 500mg"
            $table->string('frequency', 100)->default('Daily'); // e.g. "Twice Daily", "Every 8 hours"
            $table->string('scheduled_time', 50)->default('08:00'); // e.g. "08:00", "12:00", "16:00"
            $table->date('start_date');
            $table->date('end_date');
            $table->text('instructions')->nullable();
            $table->string('status', 30)->default('active'); // active, completed, cancelled
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_schedules');
    }
};
