<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->unsignedInteger('quantity_requested');
            $table->string('unit', 50)->default('Tablet');
            $table->string('priority', 30)->default('normal'); // normal, urgent, critical
            $table->string('reason', 255)->default('Stock replenishment');
            $table->string('status', 30)->default('pending'); // pending, approved, fulfilled, cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name', 255)->nullable();
            $table->date('date_requested');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['medicine_id', 'status', 'date_requested'], 'med_reqs_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_requisitions');
    }
};
