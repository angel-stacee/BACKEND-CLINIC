<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('category', 50)->index(); // medication_reminder, patient_attention, low_stock, expired_medicine
            $table->string('type', 30)->default('info'); // info, warning, danger, success
            $table->string('title');
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->string('unique_key', 191)->nullable()->unique();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_notifications');
    }
};
