<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineTransaction;
use App\Services\MedicineInventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class MedicineController extends Controller
{
    public function __construct(
        protected MedicineInventoryService $inventoryService
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_summary' => ['nullable', 'boolean'],
            'status_filter' => ['nullable', 'string', 'in:all,in_stock,low_stock,out_of_stock,expired,expiring_soon,near_expiration,good'],
        ]);
        $search = trim($validated['search'] ?? '');
        $statusFilter = $validated['status_filter'] ?? 'all';
        $today = now()->toDateString();
        $soonThreshold = now()->addDays(30)->toDateString();
        $nearThreshold = now()->addDays(90)->toDateString();

        $medicines = Medicine::query()
            ->withCount(['batches', 'activeBatches'])
            ->select([
                'id',
                'medicine_name',
                'treatment_type',
                'expiration_date',
                'unit',
                'stock',
                'minimum_stock',
                'description',
                'created_at',
                'updated_at',
            ])
            ->when($statusFilter !== 'all', function (Builder $query) use ($statusFilter, $today, $soonThreshold, $nearThreshold) {
                if ($statusFilter === 'expired') {
                    $query->whereNotNull('expiration_date')->where('expiration_date', '<', $today);
                } elseif ($statusFilter === 'expiring_soon') {
                    $query->whereNotNull('expiration_date')
                        ->where('expiration_date', '>=', $today)
                        ->where('expiration_date', '<=', $soonThreshold);
                } elseif ($statusFilter === 'near_expiration') {
                    $query->whereNotNull('expiration_date')
                        ->where('expiration_date', '>', $soonThreshold)
                        ->where('expiration_date', '<=', $nearThreshold);
                } elseif ($statusFilter === 'good') {
                    $query->where(function ($q) use ($nearThreshold) {
                        $q->whereNull('expiration_date')
                            ->orWhere('expiration_date', '>', $nearThreshold);
                    });
                } elseif ($statusFilter === 'out_of_stock') {
                    $query->where('stock', '<=', 0);
                } elseif ($statusFilter === 'low_stock') {
                    $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'minimum_stock');
                } elseif ($statusFilter === 'in_stock') {
                    $query->whereColumn('stock', '>', 'minimum_stock');
                }
            })
            ->when($search !== '', function (Builder $query) use ($search) {
                $contains = '%'.$search.'%';
                $tokens = array_values(array_filter(
                    preg_split('/[\s,]+/', $search),
                    fn ($t) => $t !== '' && $t !== '-'
                ));

                $query->where(function (Builder $query) use ($contains, $tokens) {
                    $query->where('medicine_name', 'like', $contains)
                        ->orWhere('treatment_type', 'like', $contains)
                        ->orWhere('unit', 'like', $contains)
                        ->orWhere('description', 'like', $contains);

                    if (count($tokens) > 1) {
                        $query->orWhere(function (Builder $tokenQuery) use ($tokens) {
                            foreach ($tokens as $token) {
                                $tokenContains = '%'.$token.'%';
                                $tokenQuery->where(function (Builder $tq) use ($tokenContains) {
                                    $tq->where('medicine_name', 'like', $tokenContains)
                                        ->orWhere('treatment_type', 'like', $tokenContains)
                                        ->orWhere('unit', 'like', $tokenContains)
                                        ->orWhere('description', 'like', $tokenContains);
                                });
                            }
                        });
                    }
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();

        if (! ($validated['include_summary'] ?? false)) {
            return response()->json($medicines);
        }

        $summaryQuery = Medicine::query()
            ->selectRaw('COALESCE(SUM(stock), 0) as total_stock')
            ->selectRaw('SUM(CASE WHEN stock > 0 AND stock <= minimum_stock THEN 1 ELSE 0 END) as low_stock_count')
            ->selectRaw('SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock_count')
            ->selectRaw('SUM(CASE WHEN expiration_date IS NOT NULL AND expiration_date < ? THEN 1 ELSE 0 END) as expired_count', [$today])
            ->selectRaw('SUM(CASE WHEN expiration_date IS NOT NULL AND expiration_date >= ? AND expiration_date <= ? THEN 1 ELSE 0 END) as expiring_soon_count', [$today, $soonThreshold])
            ->selectRaw('SUM(CASE WHEN expiration_date IS NOT NULL AND expiration_date > ? AND expiration_date <= ? THEN 1 ELSE 0 END) as near_expiration_count', [$soonThreshold, $nearThreshold])
            ->selectRaw('SUM(CASE WHEN expiration_date IS NULL OR expiration_date > ? THEN 1 ELSE 0 END) as good_count', [$nearThreshold]);

        $summary = $summaryQuery->first();

        // 4-Tier Batch Summary across all medicine batches
        $batchStats = MedicineBatch::query()
            ->selectRaw('COUNT(*) as total_batches')
            ->selectRaw('COALESCE(SUM(current_quantity), 0) as total_batch_stock')
            ->selectRaw('SUM(CASE WHEN expiration_date < ? THEN 1 ELSE 0 END) as expired_batch_count', [$today])
            ->selectRaw('SUM(CASE WHEN expiration_date >= ? AND expiration_date <= ? THEN 1 ELSE 0 END) as expiring_soon_batch_count', [$today, $soonThreshold])
            ->selectRaw('SUM(CASE WHEN expiration_date > ? AND expiration_date <= ? THEN 1 ELSE 0 END) as near_expiration_batch_count', [$soonThreshold, $nearThreshold])
            ->selectRaw('SUM(CASE WHEN expiration_date IS NULL OR expiration_date > ? THEN 1 ELSE 0 END) as good_batch_count', [$nearThreshold])
            ->first();

        // Also fetch up to 10 expired/expiring medicines for immediate alert banners and notifications
        $expiredItems = Medicine::query()
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<', $today)
            ->orderBy('expiration_date')
            ->limit(10)
            ->get(['id', 'medicine_name', 'expiration_date', 'stock', 'unit']);

        $expiringSoonItems = Medicine::query()
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '>=', $today)
            ->where('expiration_date', '<=', $soonThreshold)
            ->orderBy('expiration_date')
            ->limit(10)
            ->get(['id', 'medicine_name', 'expiration_date', 'stock', 'unit']);

        $earliestExpDate = Medicine::query()
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '>=', $today)
            ->where('stock', '>', 0)
            ->orderBy('expiration_date')
            ->value('expiration_date');

        $payload = $medicines->toArray();
        $payload['summary'] = [
            'total_medicines' => $medicines->total(),
            'total_stock' => (int) $summary->total_stock,
            'low_stock_count' => (int) $summary->low_stock_count,
            'out_of_stock_count' => (int) $summary->out_of_stock_count,
            'expired_count' => (int) ($summary->expired_count ?? 0),
            'expiring_soon_count' => (int) ($summary->expiring_soon_count ?? 0),
            'near_expiration_count' => (int) ($summary->near_expiration_count ?? 0),
            'good_count' => (int) ($summary->good_count ?? 0),
            'earliest_expiration_date' => $earliestExpDate ? \Carbon\Carbon::parse($earliestExpDate)->toDateString() : null,
            'total_batches' => (int) ($batchStats->total_batches ?? 0),
            'expired_batch_count' => (int) ($batchStats->expired_batch_count ?? 0),
            'expiring_soon_batch_count' => (int) ($batchStats->expiring_soon_batch_count ?? 0),
            'near_expiration_batch_count' => (int) ($batchStats->near_expiration_batch_count ?? 0),
            'good_batch_count' => (int) ($batchStats->good_batch_count ?? 0),
            'expired_medicines' => $expiredItems,
            'expiring_soon_medicines' => $expiringSoonItems,
        ];

        return response()->json($payload);
    }

    /**
     * Requirement 5: Inventory Management - Batches listing table
     * Columns: Medicine Name, Batch Number, Available Quantity, Date Added, Expiration Date, Status, Supplier, Remarks
     */
    public function batches(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'in:all,good,near_expiration,expiring_soon,expired,in_stock,out_of_stock'],
            'medicine_id' => ['nullable', 'integer', 'exists:medicines,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', 'string', 'in:fefo,date_added,medicine_name,expiration_date,stock'],
        ]);

        $search = trim($validated['search'] ?? '');
        $status = $validated['status'] ?? 'all';
        $today = now()->toDateString();
        $soonThreshold = now()->addDays(30)->toDateString();
        $nearThreshold = now()->addDays(90)->toDateString();
        $sortBy = $validated['sort_by'] ?? 'fefo';

        $query = MedicineBatch::query()
            ->with(['medicine:id,medicine_name,treatment_type,unit'])
            ->when(! empty($validated['medicine_id']), fn ($q) => $q->where('medicine_id', $validated['medicine_id']))
            ->when($status !== 'all', function (Builder $q) use ($status, $today, $soonThreshold, $nearThreshold) {
                if ($status === 'expired') {
                    $q->where('expiration_date', '<', $today);
                } elseif ($status === 'expiring_soon') {
                    $q->where('expiration_date', '>=', $today)
                        ->where('expiration_date', '<=', $soonThreshold);
                } elseif ($status === 'near_expiration') {
                    $q->where('expiration_date', '>', $soonThreshold)
                        ->where('expiration_date', '<=', $nearThreshold);
                } elseif ($status === 'good') {
                    $q->where(function ($sub) use ($nearThreshold) {
                        $sub->whereNull('expiration_date')
                            ->orWhere('expiration_date', '>', $nearThreshold);
                    });
                } elseif ($status === 'in_stock') {
                    $q->where('current_quantity', '>', 0);
                } elseif ($status === 'out_of_stock') {
                    $q->where('current_quantity', '<=', 0);
                }
            })
            ->when(! empty($validated['date_from']), fn ($q) => $q->where('date_added', '>=', $validated['date_from']))
            ->when(! empty($validated['date_to']), fn ($q) => $q->where('date_added', '<=', $validated['date_to']))
            ->when($search !== '', function (Builder $q) use ($search) {
                $contains = '%'.$search.'%';
                $q->where(function ($sub) use ($contains) {
                    $sub->where('batch_number', 'like', $contains)
                        ->orWhere('supplier', 'like', $contains)
                        ->orWhere('remarks', 'like', $contains)
                        ->orWhereHas('medicine', fn ($m) => $m->where('medicine_name', 'like', $contains));
                });
            });

        // Apply ordering (Default FEFO: nearest expiration date first, FIFO secondary)
        if ($sortBy === 'fefo') {
            $query->orderBy('expiration_date', 'asc')
                ->orderBy('date_added', 'asc')
                ->orderBy('id', 'asc');
        } elseif ($sortBy === 'date_added') {
            $query->orderByDesc('date_added')->orderByDesc('id');
        } elseif ($sortBy === 'expiration_date') {
            $query->orderBy('expiration_date', 'asc');
        } elseif ($sortBy === 'stock') {
            $query->orderByDesc('current_quantity');
        } else {
            $query->latest('id');
        }

        $batches = $query->paginate($validated['per_page'] ?? 10)->withQueryString();

        return response()->json($batches);
    }

    /**
     * Requirement 1: Add new medicine batch stock with date tracking
     */
    public function storeBatch(Request $request)
    {
        $validated = $request->validate([
            'medicine_id' => 'nullable|integer|exists:medicines,id',
            'medicine_name' => 'nullable|string|max:255',
            'treatment_type' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:100',
            'minimum_stock' => 'nullable|integer|min:0',
            'batch_number' => 'nullable|string|max:100',
            'quantity' => 'required|integer|min:1',
            'date_added' => 'nullable|date',
            'expiration_date' => 'required|date',
            'supplier' => 'nullable|string|max:255',
            'remarks' => 'nullable|string',
        ]);

        $batch = $this->inventoryService->addBatch($validated);

        return response()->json([
            'message' => "Stock of {$batch->current_quantity} {$batch->medicine->unit}(s) added to batch {$batch->batch_number}.",
            'batch' => $batch,
            'medicine' => $batch->medicine->fresh(),
        ], 201);
    }

    /**
     * Requirement 2 & 4: Automatic Stock Issuing using FEFO and FIFO
     */
    public function dispense(Request $request)
    {
        $validated = $request->validate([
            'medicine_id' => 'required|integer|exists:medicines,id',
            'quantity' => 'required|integer|min:1',
            'issued_to_type' => 'nullable|string|max:50', // student, faculty, staff, walk-in
            'issued_to_id' => 'nullable|string|max:100',
            'issued_to_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        $medicine = Medicine::findOrFail($validated['medicine_id']);

        $result = $this->inventoryService->issueStock(
            $medicine,
            (int) $validated['quantity'],
            [
                'type' => $validated['issued_to_type'] ?? 'student',
                'id' => $validated['issued_to_id'] ?? null,
                'name' => $validated['issued_to_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'message' => "Successfully issued {$validated['quantity']} {$medicine->unit}(s) of {$medicine->medicine_name} via FEFO.",
            'result' => $result,
        ]);
    }

    /**
     * Preview FEFO batch selection for dispensing
     */
    public function previewDispense(Request $request)
    {
        $validated = $request->validate([
            'medicine_id' => 'required|integer|exists:medicines,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $preview = $this->inventoryService->previewFefoDeduction(
            (int) $validated['medicine_id'],
            (int) $validated['quantity']
        );

        return response()->json($preview);
    }

    /**
     * Requirement 4 & 7: Audit trail / Distribution History
     */
    public function transactions(Request $request)
    {
        $validated = $request->validate([
            'medicine_id' => 'nullable|integer|exists:medicines,id',
            'transaction_type' => 'nullable|string|in:all,stock_in,dispense,return,adjustment',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $type = $validated['transaction_type'] ?? 'all';
        $search = trim($validated['search'] ?? '');

        $query = MedicineTransaction::query()
            ->with(['medicine:id,medicine_name,unit', 'batch:id,batch_number,expiration_date', 'user:id,name', 'clinicVisit:id,visit_date,reason'])
            ->when(! empty($validated['medicine_id']), fn ($q) => $q->where('medicine_id', $validated['medicine_id']))
            ->when($type !== 'all', fn ($q) => $q->where('transaction_type', $type))
            ->when($search !== '', function (Builder $q) use ($search) {
                $contains = '%'.$search.'%';
                $q->where(function ($sub) use ($contains) {
                    $sub->where('batch_number', 'like', $contains)
                        ->orWhere('issued_to_name', 'like', $contains)
                        ->orWhere('issued_to_id', 'like', $contains)
                        ->orWhere('notes', 'like', $contains)
                        ->orWhereHas('medicine', fn ($m) => $m->where('medicine_name', 'like', $contains));
                });
            })
            ->latest('id');

        return response()->json($query->paginate($validated['per_page'] ?? 15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'medicine_name' => 'required|string|max:255',
            'treatment_type' => 'nullable|string|max:255',
            'expiration_date' => 'nullable|date',
            'unit' => 'required|string|max:100',
            'stock' => 'required|integer|min:0',
            'minimum_stock' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'batch_number' => 'nullable|string|max:100',
            'date_added' => 'nullable|date',
            'supplier' => 'nullable|string|max:255',
            'remarks' => 'nullable|string',
        ]);

        $initialStock = (int) $validated['stock'];
        $validated['stock'] = 0; // Will be set by recalculateStock() after batch creation

        $medicine = Medicine::create($validated);

        if ($initialStock > 0) {
            $this->inventoryService->addBatch([
                'medicine_id' => $medicine->id,
                'batch_number' => $validated['batch_number'] ?? null,
                'quantity' => $initialStock,
                'date_added' => $validated['date_added'] ?? now()->toDateString(),
                'expiration_date' => $validated['expiration_date'] ?? now()->addYears(2)->toDateString(),
                'supplier' => $validated['supplier'] ?? null,
                'remarks' => $validated['remarks'] ?? 'Initial stock entry',
            ]);
        } else {
            $medicine->stock = 0;
            $medicine->save();
        }

        try {
            app(\App\Services\NotificationService::class)->syncAll();
        } catch (\Throwable) {}

        return response()->json([
            'message' => 'Medicine added successfully.',
            'medicine' => $medicine->fresh()->load('batches'),
        ], 201);
    }

    public function show(Medicine $medicine)
    {
        return response()->json($medicine->load(['batches' => fn ($q) => $q->orderBy('expiration_date')]));
    }

    public function update(Request $request, Medicine $medicine)
    {
        $validated = $request->validate([
            'medicine_name' => 'required|string|max:255',
            'treatment_type' => 'nullable|string|max:255',
            'expiration_date' => 'nullable|date',
            'unit' => 'required|string|max:100',
            'stock' => 'required|integer|min:0',
            'minimum_stock' => 'required|integer|min:0',
            'description' => 'nullable|string',
        ]);

        $oldStock = (int) $medicine->stock;
        $newStock = (int) $validated['stock'];

        $medicine->update($validated);

        // If stock was adjusted directly in edit modal, record difference in latest batch or create adjustment
        if ($newStock !== $oldStock) {
            $diff = $newStock - $oldStock;
            if ($diff > 0 && $medicine->expiration_date) {
                // Additional stock
                $this->inventoryService->addBatch([
                    'medicine_id' => $medicine->id,
                    'quantity' => $diff,
                    'date_added' => now()->toDateString(),
                    'expiration_date' => $medicine->expiration_date,
                    'remarks' => 'Stock adjustment via edit',
                ]);
            }
        }

        $medicine->recalculateStock();

        try {
            app(\App\Services\NotificationService::class)->syncAll();
        } catch (\Throwable) {}

        return response()->json([
            'message' => 'Medicine updated successfully.',
            'medicine' => $medicine->fresh(),
        ]);
    }

    public function destroy(Medicine $medicine)
    {
        $medicine->delete();

        return response()->json([
            'message' => 'Medicine record moved to trash.',
            'id' => $medicine->id,
        ]);
    }

    public function trashed(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $query = Medicine::onlyTrashed();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('medicine_name', 'like', "%{$search}%")
                    ->orWhere('treatment_type', 'like', "%{$search}%")
                    ->orWhere('unit', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->get('per_page', 10)));
        return response()->json($query->orderByDesc('deleted_at')->paginate($perPage));
    }

    public function restore($id)
    {
        $medicine = Medicine::onlyTrashed()->findOrFail($id);
        $medicine->restore();

        return response()->json([
            'message' => 'Medicine record restored successfully.',
            'medicine' => $medicine,
        ]);
    }

    public function forceDelete($id)
    {
        $medicine = Medicine::withTrashed()->findOrFail($id);
        $medicine->forceDelete();

        return response()->json([
            'message' => 'Medicine permanently deleted.',
        ]);
    }
}
