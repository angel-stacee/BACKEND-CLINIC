<?php

namespace App\Services;

use App\Models\ClinicVisit;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MedicineInventoryService
{
    /**
     * Records a new medicine batch / stock addition.
     * Ensures every stock addition is recorded separately even for the same medicine name.
     */
    public function addBatch(array $data): MedicineBatch
    {
        return DB::transaction(function () use ($data) {
            $medicine = null;

            if (! empty($data['medicine_id'])) {
                $medicine = Medicine::findOrFail($data['medicine_id']);
            } elseif (! empty($data['medicine_name'])) {
                $medicine = Medicine::firstOrCreate(
                    ['medicine_name' => trim($data['medicine_name'])],
                    [
                        'treatment_type' => $data['treatment_type'] ?? 'Medication',
                        'unit' => $data['unit'] ?? 'Piece',
                        'stock' => 0,
                        'minimum_stock' => $data['minimum_stock'] ?? 10,
                        'description' => $data['description'] ?? null,
                    ]
                );
            } else {
                throw ValidationException::withMessages([
                    'medicine_id' => ['Medicine is required.'],
                ]);
            }

            $quantity = (int) $data['quantity'];
            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['Quantity added must be greater than zero.'],
                ]);
            }

            $dateAdded = ! empty($data['date_added'])
                ? Carbon::parse($data['date_added'])->toDateString()
                : now()->toDateString();

            $expDate = ! empty($data['expiration_date'])
                ? Carbon::parse($data['expiration_date'])->toDateString()
                : null;

            if (! $expDate) {
                throw ValidationException::withMessages([
                    'expiration_date' => ['Expiration date is required for batch inventory tracking.'],
                ]);
            }

            // Generate batch number if not provided
            $batchNumber = ! empty($data['batch_number'])
                ? trim($data['batch_number'])
                : 'BATCH-' . date('Ymd') . '-' . strtoupper(Str::random(4));

            $batch = MedicineBatch::create([
                'medicine_id' => $medicine->id,
                'batch_number' => $batchNumber,
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
                'date_added' => $dateAdded,
                'expiration_date' => $expDate,
                'supplier' => $data['supplier'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            MedicineTransaction::create([
                'medicine_id' => $medicine->id,
                'medicine_batch_id' => $batch->id,
                'transaction_type' => 'stock_in',
                'quantity' => $quantity,
                'batch_number' => $batchNumber,
                'expiration_date' => $expDate,
                'performed_by' => auth()->id(),
                'notes' => $data['remarks'] ?? "Stock added: {$quantity} {$medicine->unit}(s) via {$batchNumber}",
            ]);

            $medicine->recalculateStock();

            try {
                app(NotificationService::class)->syncAll();
            } catch (\Throwable) {}

            return $batch->load('medicine');
        });
    }

    /**
     * Automatic Stock Issuing using FEFO (First Expired, First Out)
     * with secondary FIFO (First In, First Out).
     *
     * - Prioritizes batch that expires soonest.
     * - Never issues expired medicine.
     * - If same expiration date, issues batch added first.
     * - Deducts across multiple batches if requested quantity exceeds one batch.
     */
    public function issueStock(
        Medicine $medicine,
        int $quantityRequested,
        array $recipient = [],
        ?ClinicVisit $clinicVisit = null,
        string $strategy = 'fefo'
    ): array {
        if ($quantityRequested <= 0) {
            throw ValidationException::withMessages([
                'medicine_quantity' => ['Quantity to issue must be at least 1.'],
            ]);
        }

        $strategy = strtolower(trim($strategy));
        if (! in_array($strategy, ['fefo', 'fifo', 'lifo'], true)) {
            $strategy = 'fefo';
        }

        return DB::transaction(function () use ($medicine, $quantityRequested, $recipient, $clinicVisit, $strategy) {
            // Lock medicine row to prevent concurrent race conditions
            $lockedMed = Medicine::where('id', $medicine->id)->lockForUpdate()->firstOrFail();

            $today = now()->toDateString();

            // Fetch available unexpired batches ordered by the selected rotation strategy (never allow expired)
            $batchQuery = MedicineBatch::query()
                ->where('medicine_id', $lockedMed->id)
                ->where('current_quantity', '>', 0)
                ->where(function ($q) use ($today) {
                    $q->whereNull('expiration_date')->orWhere('expiration_date', '>=', $today);
                });

            if ($strategy === 'fifo') {
                $batchQuery->orderBy('date_added', 'asc')->orderBy('id', 'asc');
            } elseif ($strategy === 'lifo') {
                $batchQuery->orderBy('date_added', 'desc')->orderBy('id', 'desc');
            } else {
                // Default FEFO: earliest expiration date first, then FIFO tie-breaker
                $batchQuery->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END ASC')
                    ->orderBy('expiration_date', 'asc')
                    ->orderBy('date_added', 'asc')
                    ->orderBy('id', 'asc');
            }

            $batches = $batchQuery->lockForUpdate()->get();

            // If legacy record has stock but no batches registered yet, auto-create initial batch
            if ($batches->isEmpty() && (int) $lockedMed->stock > 0) {
                $batch = MedicineBatch::create([
                    'medicine_id' => $lockedMed->id,
                    'batch_number' => 'BATCH-' . str_pad((string) $lockedMed->id, 4, '0', STR_PAD_LEFT),
                    'initial_quantity' => (int) $lockedMed->stock,
                    'current_quantity' => (int) $lockedMed->stock,
                    'date_added' => $lockedMed->created_at ? substr($lockedMed->created_at, 0, 10) : $today,
                    'expiration_date' => $lockedMed->expiration_date ?: Carbon::parse($today)->addYear()->toDateString(),
                    'supplier' => 'Baseline Stock',
                ]);
                $batches = collect([$batch]);
            }

            $totalAvailable = $batches->sum('current_quantity');

            if ($totalAvailable < $quantityRequested) {
                throw ValidationException::withMessages([
                    'medicine_quantity' => [
                        "Insufficient unexpired stock for '{$lockedMed->medicine_name}'. Requested: {$quantityRequested}, Available unexpired stock: {$totalAvailable} {$lockedMed->unit}(s)."
                    ],
                ]);
            }

            $remaining = $quantityRequested;
            $deductions = [];

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $deduct = min($remaining, $batch->current_quantity);
                $batch->current_quantity -= $deduct;
                $batch->save();

                MedicineTransaction::create([
                    'medicine_id' => $lockedMed->id,
                    'medicine_batch_id' => $batch->id,
                    'clinic_visit_id' => $clinicVisit?->id,
                    'transaction_type' => 'dispense',
                    'quantity' => $deduct,
                    'batch_number' => $batch->batch_number,
                    'expiration_date' => $batch->expiration_date,
                    'issued_to_type' => $recipient['type'] ?? null,
                    'issued_to_id' => $recipient['id'] ?? null,
                    'issued_to_name' => $recipient['name'] ?? null,
                    'performed_by' => auth()->id(),
                    'notes' => $recipient['notes'] ?? ($clinicVisit ? "Dispensed during clinic visit #{$clinicVisit->id}" : 'Direct clinic stock issue'),
                ]);

                $deductions[] = [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'expiration_date' => $batch->expiration_date ? Carbon::parse($batch->expiration_date)->format('Y-m-d') : null,
                    'quantity' => $deduct,
                ];

                $remaining -= $deduct;
            }

            $lockedMed->recalculateStock();

            try {
                app(NotificationService::class)->syncAll();
            } catch (\Throwable) {}

            return [
                'medicine_id' => $lockedMed->id,
                'medicine_name' => $lockedMed->medicine_name,
                'quantity_issued' => $quantityRequested,
                'remaining_stock' => $lockedMed->stock,
                'batches_used' => $deductions,
            ];
        });
    }

    /**
     * Restores stock back to inventory (e.g. when a clinic visit is cancelled/deleted)
     */
    public function restoreStock(int $medicineId, int $quantity, ?ClinicVisit $clinicVisit = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        DB::transaction(function () use ($medicineId, $quantity, $clinicVisit) {
            $medicine = Medicine::where('id', $medicineId)->lockForUpdate()->first();
            if (! $medicine) {
                return;
            }

            // If we have transaction records for this clinic visit, restore directly to those batches
            if ($clinicVisit) {
                $dispenseTxs = MedicineTransaction::where('clinic_visit_id', $clinicVisit->id)
                    ->where('medicine_id', $medicineId)
                    ->where('transaction_type', 'dispense')
                    ->get();

                if ($dispenseTxs->isNotEmpty()) {
                    foreach ($dispenseTxs as $tx) {
                        if ($tx->medicine_batch_id) {
                            $batch = MedicineBatch::where('id', $tx->medicine_batch_id)->lockForUpdate()->first();
                            if ($batch) {
                                $batch->current_quantity += $tx->quantity;
                                $batch->save();
                            }
                        }

                        MedicineTransaction::create([
                            'medicine_id' => $medicineId,
                            'medicine_batch_id' => $tx->medicine_batch_id,
                            'clinic_visit_id' => $clinicVisit->id,
                            'transaction_type' => 'return',
                            'quantity' => $tx->quantity,
                            'batch_number' => $tx->batch_number,
                            'expiration_date' => $tx->expiration_date,
                            'performed_by' => auth()->id(),
                            'notes' => "Restored from cancelled clinic visit #{$clinicVisit->id}",
                        ]);
                    }

                    $medicine->recalculateStock();
                    return;
                }
            }

            // Fallback: restore to the latest active batch or default batch
            $targetBatch = MedicineBatch::where('medicine_id', $medicineId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($targetBatch) {
                $targetBatch->current_quantity += $quantity;
                $targetBatch->save();
            }

            MedicineTransaction::create([
                'medicine_id' => $medicineId,
                'medicine_batch_id' => $targetBatch?->id,
                'clinic_visit_id' => $clinicVisit?->id,
                'transaction_type' => 'return',
                'quantity' => $quantity,
                'batch_number' => $targetBatch?->batch_number,
                'expiration_date' => $targetBatch?->expiration_date,
                'performed_by' => auth()->id(),
                'notes' => 'Stock returned to inventory',
            ]);

            $medicine->recalculateStock();
        });
    }

    /**
     * Preview which batches will be used under FEFO/FIFO without mutating data
     */
    public function previewFefoDeduction(int $medicineId, int $quantity): array
    {
        $today = now()->toDateString();
        $batches = MedicineBatch::query()
            ->where('medicine_id', $medicineId)
            ->where('current_quantity', '>', 0)
            ->where(function ($q) use ($today) {
                $q->whereNull('expiration_date')->orWhere('expiration_date', '>=', $today);
            })
            ->orderBy('expiration_date', 'asc')
            ->orderBy('date_added', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $totalAvailable = $batches->sum('current_quantity');
        $remaining = $quantity;
        $plan = [];

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $deduct = min($remaining, $batch->current_quantity);
            $plan[] = [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'expiration_date' => $batch->expiration_date ? Carbon::parse($batch->expiration_date)->format('Y-m-d') : null,
                'days_until_expiration' => $batch->days_until_expiration,
                'status' => $batch->status,
                'available' => $batch->current_quantity,
                'will_deduct' => $deduct,
            ];
            $remaining -= $deduct;
        }

        return [
            'available_unexpired_stock' => $totalAvailable,
            'is_sufficient' => $totalAvailable >= $quantity,
            'plan' => $plan,
        ];
    }
}
