<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->string('batch_number', 100)->nullable()->index();
            $table->unsignedInteger('initial_quantity')->default(0);
            $table->unsignedInteger('current_quantity')->default(0);
            $table->date('date_added')->index();
            $table->date('expiration_date')->index();
            $table->string('supplier', 255)->nullable();
            $table->text('remarks')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['medicine_id', 'current_quantity', 'expiration_date'], 'med_batches_fefo_idx');
        });

        Schema::create('medicine_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->foreignId('medicine_batch_id')->nullable()->constrained('medicine_batches')->nullOnDelete();
            $table->foreignId('clinic_visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
            $table->string('transaction_type', 50)->index(); // stock_in, dispense, return, adjustment, discard_expired
            $table->integer('quantity'); // positive for added/returned, positive quantity issued
            $table->string('batch_number', 100)->nullable();
            $table->date('expiration_date')->nullable();
            $table->string('issued_to_type', 50)->nullable(); // student, faculty, staff, walk-in
            $table->string('issued_to_id', 100)->nullable();
            $table->string('issued_to_name', 255)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['medicine_id', 'transaction_type', 'created_at'], 'med_transactions_lookup_idx');
        });

        // Backfill existing medicines with initial batch and transaction so no stock is lost
        $today = now()->toDateString();
        $medicines = DB::table('medicines')->whereNull('deleted_at')->get();

        foreach ($medicines as $med) {
            $stock = max(0, (int) $med->stock);
            $dateAdded = $med->created_at ? substr($med->created_at, 0, 10) : $today;
            $expDate = $med->expiration_date ?: date('Y-m-d', strtotime('+1 year'));
            $batchNum = 'BATCH-' . str_pad((string) $med->id, 4, '0', STR_PAD_LEFT);

            $batchId = DB::table('medicine_batches')->insertGetId([
                'medicine_id' => $med->id,
                'batch_number' => $batchNum,
                'initial_quantity' => $stock,
                'current_quantity' => $stock,
                'date_added' => $dateAdded,
                'expiration_date' => $expDate,
                'supplier' => 'Existing Stock',
                'remarks' => 'Initial inventory migration baseline',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($stock > 0) {
                DB::table('medicine_transactions')->insert([
                    'medicine_id' => $med->id,
                    'medicine_batch_id' => $batchId,
                    'transaction_type' => 'stock_in',
                    'quantity' => $stock,
                    'batch_number' => $batchNum,
                    'expiration_date' => $expDate,
                    'notes' => 'Initial inventory baseline',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_transactions');
        Schema::dropIfExists('medicine_batches');
    }
};
