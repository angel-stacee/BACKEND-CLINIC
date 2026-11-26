<?php

namespace App\Http\Controllers;

use App\Models\ClinicVisit;
use App\Models\Faculty;
use App\Models\Medicine;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicVisitController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'faculty_id' => ['nullable', 'integer', 'exists:faculties,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($validated['search'] ?? '');
        $dateSearch = $this->normalizeVisitDateSearch($search);

        $query = ClinicVisit::query()
            ->select([
                'id',
                'student_id',
                'faculty_id',
                'staff_id',
                'nurse_id',
                'department',
                'medicine_id',
                'medicine_quantity',
                'visit_date',
                'reason',
                'symptoms',
                'temperature',
                'blood_pressure',
                'assessment',
                'treatment',
                'remarks',
                'created_at',
                'updated_at',
            ])
            ->with([
                'student:id,student_id,first_name,middle_name,last_name,course,year_level,section',
                'faculty:id,employee_id,first_name,middle_name,last_name,department,position',
                'staff:id,staff_id,first_name,middle_name,last_name,department,position',
                'nurse:id,name,email,role',
                'medicine:id,medicine_name,unit',
            ])
            ->when($validated['student_id'] ?? null, fn (Builder $query, int $id) => $query->where('student_id', $id))
            ->when($validated['faculty_id'] ?? null, fn (Builder $query, int $id) => $query->where('faculty_id', $id))
            ->when($validated['staff_id'] ?? null, fn (Builder $query, int $id) => $query->where('staff_id', $id))
            ->when($search !== '', function (Builder $query) use ($search, $dateSearch) {
                $term = $search;
                $contains = '%'.$term.'%';
                $tokens = array_values(array_filter(
                    preg_split('/[\s,]+/', $term),
                    fn ($token) => $token !== '' && $token !== '-'
                ));

                $query->where(function (Builder $query) use ($contains, $dateSearch, $tokens) {
                    $query->where('reason', 'like', $contains)
                        ->orWhere('symptoms', 'like', $contains)
                        ->orWhere('temperature', 'like', $contains)
                        ->orWhere('blood_pressure', 'like', $contains)
                        ->orWhere('assessment', 'like', $contains)
                        ->orWhere('treatment', 'like', $contains)
                        ->orWhere('remarks', 'like', $contains)
                        ->orWhere('department', 'like', $contains)
                        ->orWhere('visit_date', 'like', $contains)
                        ->when($dateSearch, fn (Builder $dateQuery, string $date) => $dateQuery->orWhereDate('visit_date', $date))
                        ->orWhereRaw('CAST(medicine_quantity AS CHAR) LIKE ?', [$contains])
                        ->orWhereHas('student', function (Builder $studentQuery) use ($contains, $tokens) {
                            $studentQuery->where(function (Builder $sq) use ($contains, $tokens) {
                                $sq->where('student_id', 'like', $contains)
                                    ->orWhere('first_name', 'like', $contains)
                                    ->orWhere('middle_name', 'like', $contains)
                                    ->orWhere('last_name', 'like', $contains);

                                if (count($tokens) > 1) {
                                    $sq->orWhere(function (Builder $tq) use ($tokens) {
                                        foreach ($tokens as $token) {
                                            $tokenContains = '%'.$token.'%';
                                            $tq->where(function (Builder $fq) use ($tokenContains) {
                                                $fq->where('student_id', 'like', $tokenContains)
                                                    ->orWhere('first_name', 'like', $tokenContains)
                                                    ->orWhere('middle_name', 'like', $tokenContains)
                                                    ->orWhere('last_name', 'like', $tokenContains);
                                            });
                                        }
                                    });
                                }
                            });
                        })
                        ->orWhereHas('faculty', function (Builder $facultyQuery) use ($contains, $tokens) {
                            $facultyQuery->where(function (Builder $fq) use ($contains, $tokens) {
                                $fq->where('employee_id', 'like', $contains)
                                    ->orWhere('first_name', 'like', $contains)
                                    ->orWhere('middle_name', 'like', $contains)
                                    ->orWhere('last_name', 'like', $contains);

                                if (count($tokens) > 1) {
                                    $fq->orWhere(function (Builder $tq) use ($tokens) {
                                        foreach ($tokens as $token) {
                                            $tokenContains = '%'.$token.'%';
                                            $tq->where(function (Builder $fieldQuery) use ($tokenContains) {
                                                $fieldQuery->where('employee_id', 'like', $tokenContains)
                                                    ->orWhere('first_name', 'like', $tokenContains)
                                                    ->orWhere('middle_name', 'like', $tokenContains)
                                                    ->orWhere('last_name', 'like', $tokenContains);
                                            });
                                        }
                                    });
                                }
                            });
                        })
                        ->orWhereHas('staff', function (Builder $staffQuery) use ($contains, $tokens) {
                            $staffQuery->where(function (Builder $sq) use ($contains, $tokens) {
                                $sq->where('staff_id', 'like', $contains)
                                    ->orWhere('first_name', 'like', $contains)
                                    ->orWhere('middle_name', 'like', $contains)
                                    ->orWhere('last_name', 'like', $contains);

                                if (count($tokens) > 1) {
                                    $sq->orWhere(function (Builder $tq) use ($tokens) {
                                        foreach ($tokens as $token) {
                                            $tokenContains = '%'.$token.'%';
                                            $tq->where(function (Builder $fieldQuery) use ($tokenContains) {
                                                $fieldQuery->where('staff_id', 'like', $tokenContains)
                                                    ->orWhere('first_name', 'like', $tokenContains)
                                                    ->orWhere('middle_name', 'like', $tokenContains)
                                                    ->orWhere('last_name', 'like', $tokenContains);
                                            });
                                        }
                                    });
                                }
                            });
                        })
                        ->orWhereHas('nurse', fn (Builder $nurseQuery) => $nurseQuery->where('name', 'like', $contains))
                        ->orWhereHas('medicine', fn (Builder $medicineQuery) => $medicineQuery->where('medicine_name', 'like', $contains));
                });
            })
            ->latest('visit_date')
            ->latest('id');

        return response()->json(
            $query->paginate($validated['per_page'] ?? 25)->withQueryString()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => [
                'nullable',
                'exists:students,id',
            ],

            'faculty_id' => [
                'nullable',
                'exists:faculties,id',
            ],

            'staff_id' => [
                'nullable',
                'exists:staff,id',
            ],

            'nurse_id' => [
                'required',
                'exists:users,id',
            ],

            'visit_date' => [
                'required',
                'date_format:Y-m-d',
            ],

            'reason' => [
                'required',
                'string',
                'max:1000',
            ],

            'symptoms' => [
                'nullable',
                'string',
            ],

            'temperature' => [
                'nullable',
                'string',
                'max:50',
            ],

            'blood_pressure' => [
                'nullable',
                'string',
                'max:50',
            ],

            'assessment' => [
                'nullable',
                'string',
            ],

            'treatment' => [
                'nullable',
                'string',
            ],

            'medicine_id' => [
                'nullable',
                'exists:medicines,id',
            ],

            'medicine_quantity' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'department' => [
                'nullable',
                'string',
                'max:255',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ]);

        $patientIds = [
            'student_id' => $validated['student_id'] ?? null,
            'faculty_id' => $validated['faculty_id'] ?? null,
            'staff_id' => $validated['staff_id'] ?? null,
        ];

        $selectedPatients = collect($patientIds)
            ->filter(fn ($value) => ! empty($value))
            ->count();

        if ($selectedPatients === 0) {
            throw ValidationException::withMessages([
                'patient' => [
                    'Please select a Student, Faculty, or Staff.',
                ],
            ]);
        }

        if ($selectedPatients > 1) {
            throw ValidationException::withMessages([
                'patient' => [
                    'Please select only one patient.',
                ],
            ]);
        }

        $validated = $this->normalizeMedicineData($validated);

        $this->validateNurse($validated['nurse_id']);

        $validated['temperature'] = $this->normalizeTemperature($validated['temperature'] ?? null);
        $validated['department'] = $this->resolveDepartment($validated);

        $visit = DB::transaction(function () use ($validated) {
            $createdVisit = ClinicVisit::create($validated);

            if (! empty($validated['medicine_id'])) {
                $medicine = Medicine::where('id', $validated['medicine_id'])->lockForUpdate()->first();
                if (! $medicine) {
                    throw ValidationException::withMessages([
                        'medicine_id' => ['Selected medicine was not found.'],
                    ]);
                }

                $quantity = (int) $validated['medicine_quantity'];
                $recipient = $this->resolveRecipientData($validated);

                app(\App\Services\MedicineInventoryService::class)->issueStock(
                    $medicine,
                    $quantity,
                    $recipient,
                    $createdVisit
                );
            }

            return $createdVisit;
        });

        try {
            app(\App\Services\NotificationService::class)->syncAll();
        } catch (\Throwable) {}

        return response()->json(
            $visit->load([
                'student',
                'faculty',
                'staff',
                'nurse',
                'medicine',
            ]),
            201
        );
    }

    public function show(ClinicVisit $clinicVisit)
    {
        return response()->json(
            $clinicVisit->load([
                'student',
                'faculty',
                'staff',
                'nurse',
                'medicine',
            ])
        );
    }

    public function update(
        Request $request,
        ClinicVisit $clinicVisit
    ) {
        $validated = $request->validate([
            'student_id' => [
                'nullable',
                'exists:students,id',
            ],

            'faculty_id' => [
                'nullable',
                'exists:faculties,id',
            ],

            'staff_id' => [
                'nullable',
                'exists:staff,id',
            ],

            'nurse_id' => [
                'required',
                'exists:users,id',
            ],

            'visit_date' => [
                'required',
                'date',
            ],

            'reason' => [
                'required',
                'string',
                'max:1000',
            ],

            'symptoms' => [
                'nullable',
                'string',
            ],

            'temperature' => [
                'nullable',
                'string',
                'max:50',
            ],

            'blood_pressure' => [
                'nullable',
                'string',
                'max:50',
            ],

            'assessment' => [
                'nullable',
                'string',
            ],

            'treatment' => [
                'nullable',
                'string',
            ],

            'medicine_id' => [
                'nullable',
                'exists:medicines,id',
            ],

            'medicine_quantity' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'department' => [
                'nullable',
                'string',
                'max:255',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ]);

        $patientIds = [
            'student_id' => $validated['student_id'] ?? null,
            'faculty_id' => $validated['faculty_id'] ?? null,
            'staff_id' => $validated['staff_id'] ?? null,
        ];

        $selectedPatients = collect($patientIds)
            ->filter(fn ($value) => ! empty($value))
            ->count();

        if ($selectedPatients === 0) {
            throw ValidationException::withMessages([
                'patient' => [
                    'Please select a Student, Faculty, or Staff.',
                ],
            ]);
        }

        if ($selectedPatients > 1) {
            throw ValidationException::withMessages([
                'patient' => [
                    'Please select only one patient.',
                ],
            ]);
        }

        $validated = $this->normalizeMedicineData($validated);

        $this->validateNurse($validated['nurse_id']);

        $validated['temperature'] = $this->normalizeTemperature($validated['temperature'] ?? null);
        $validated['department'] = $this->resolveDepartment($validated);

        DB::transaction(function () use (
            $validated,
            $clinicVisit
        ) {

            $oldMedicineId =
                $clinicVisit->medicine_id;

            $oldQuantity = (int) (
                $clinicVisit->medicine_quantity ?? 0
            );

            $newMedicineId =
                $validated['medicine_id'] ?? null;

            $newQuantity = $newMedicineId
                ? (int) (
                    $validated['medicine_quantity'] ?? 0
                )
                : 0;

            $inventoryService = app(\App\Services\MedicineInventoryService::class);

            if ($oldMedicineId && $oldQuantity > 0 && ($oldMedicineId !== $newMedicineId || $oldQuantity !== $newQuantity)) {
                $inventoryService->restoreStock((int) $oldMedicineId, $oldQuantity, $clinicVisit);
            }

            if ($newMedicineId && $newQuantity > 0 && ($oldMedicineId !== $newMedicineId || $oldQuantity !== $newQuantity)) {
                $newMedicine = Medicine::where('id', $newMedicineId)->lockForUpdate()->first();
                if (! $newMedicine) {
                    throw ValidationException::withMessages([
                        'medicine_id' => ['Selected medicine was not found.'],
                    ]);
                }
                $recipient = $this->resolveRecipientData($validated);
                $inventoryService->issueStock($newMedicine, $newQuantity, $recipient, $clinicVisit);
            }

            $clinicVisit->update($validated);
        });

        return response()->json(
            $clinicVisit
                ->fresh()
                ->load([
                    'student',
                    'faculty',
                    'staff',
                    'nurse',
                    'medicine',
                ])
        );
    }

    public function destroy(ClinicVisit $clinicVisit)
    {
        DB::transaction(function () use ($clinicVisit) {
            $medicineId = $clinicVisit->medicine_id;
            $quantity = (int) ($clinicVisit->medicine_quantity ?? 0);

            if ($medicineId && $quantity > 0) {
                app(\App\Services\MedicineInventoryService::class)->restoreStock((int) $medicineId, $quantity, $clinicVisit);
            }

            $clinicVisit->delete();
        });

        return response()->json([
            'message' => 'Clinic visit moved to trash.',
            'id' => $clinicVisit->id,
        ]);
    }

    public function trashed(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $query = ClinicVisit::onlyTrashed()
            ->with([
                'student:id,student_id,first_name,middle_name,last_name,course,year_level,section',
                'faculty:id,employee_id,first_name,middle_name,last_name,department,position',
                'staff:id,staff_id,first_name,middle_name,last_name,department,position',
                'nurse:id,name',
                'medicine:id,medicine_name,unit',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', "%{$search}%")
                    ->orWhere('symptoms', 'like', "%{$search}%")
                    ->orWhere('treatment', 'like', "%{$search}%")
                    ->orWhereHas('student', function ($sq) use ($search) {
                        $sq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%");
                    })
                    ->orWhereHas('staff', function ($sq) use ($search) {
                        $sq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('staff_id', 'like', "%{$search}%");
                    })
                    ->orWhereHas('faculty', function ($sq) use ($search) {
                        $sq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('employee_id', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = max(1, min(100, (int) $request->get('per_page', 10)));
        return response()->json($query->orderByDesc('deleted_at')->paginate($perPage));
    }

    public function restore($id)
    {
        $visit = ClinicVisit::onlyTrashed()->findOrFail($id);

        DB::transaction(function () use ($visit) {
            $medicineId = $visit->medicine_id;
            $quantity = (int) ($visit->medicine_quantity ?? 0);

            if ($medicineId && $quantity > 0) {
                $medicine = Medicine::where('id', $medicineId)->lockForUpdate()->first();
                if ($medicine) {
                    $medicine->stock = max(0, (int) $medicine->stock - $quantity);
                    $medicine->save();
                }
            }

            $visit->restore();
        });

        return response()->json([
            'message' => 'Clinic visit restored successfully.',
            'clinic_visit' => $visit->load([
                'student:id,student_id,first_name,middle_name,last_name,course,year_level,section',
                'faculty:id,employee_id,first_name,middle_name,last_name,department,position',
                'staff:id,staff_id,first_name,middle_name,last_name,department,position',
                'nurse:id,name',
                'medicine:id,medicine_name,unit',
            ]),
        ]);
    }

    public function forceDelete($id)
    {
        $visit = ClinicVisit::onlyTrashed()->findOrFail($id);
        $visit->forceDelete();

        return response()->json([
            'message' => 'Clinic visit permanently deleted.',
        ]);
    }

    private function normalizeMedicineData(
        array $validated
    ): array {

        if (! empty($validated['medicine_id'])) {

            if (
                empty(
                    $validated['medicine_quantity']
                ) ||
                (int) $validated[
                    'medicine_quantity'
                ] < 1
            ) {

                throw ValidationException::withMessages([
                    'medicine_quantity' => [
                        'Quantity used must be at least 1.',
                    ],
                ]);
            }

            $validated['medicine_quantity'] =
                (int) $validated[
                    'medicine_quantity'
                ];

        } else {

            $validated['medicine_id'] = null;

            $validated['medicine_quantity'] = null;
        }

        return $validated;
    }

    private function normalizeVisitDateSearch(string $search): ?string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $search, $matches)) {
            [$month, $day, $year] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $search, $matches)) {
            [$year, $month, $day] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        } else {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function validateNurse(
        $nurseId
    ) {
        $nurse = User::where('id', $nurseId)->first();

        if (! $nurse) {
            throw ValidationException::withMessages([
                'nurse_id' => [
                    'Selected nurse or attendant was not found.',
                ],
            ]);
        }
    }

    public function nurses()
    {
        return response()->json(
            User::query()
                ->select(
                    'id',
                    'name',
                    'email',
                    'role'
                )
                ->orderBy('name')
                ->get()
        );
    }

    public function options(Request $request)
    {
        $validated = $request->validate([
            'patient_search' => ['nullable', 'string', 'max:100'],
            'medicine_search' => ['nullable', 'string', 'max:100'],
        ]);

        $patientSearch = trim($validated['patient_search'] ?? '');
        $medicineSearch = trim($validated['medicine_search'] ?? '');
        $patientTokens = array_values(array_filter(
            preg_split('/[\s,]+/', $patientSearch),
            fn ($token) => $token !== '' && $token !== '-'
        ));
        $payload = [];

        if (! $request->has('medicine_search')) {
            $payload['students'] = Student::query()
                ->select(['id', 'student_id', 'first_name', 'middle_name', 'last_name', 'course'])
                ->when($patientSearch !== '', function (Builder $query) use ($patientSearch, $patientTokens) {
                    $contains = '%'.$patientSearch.'%';
                    $query->where(function (Builder $subQuery) use ($contains, $patientTokens) {
                        $subQuery->where('student_id', 'like', $contains)
                            ->orWhere('first_name', 'like', $contains)
                            ->orWhere('middle_name', 'like', $contains)
                            ->orWhere('last_name', 'like', $contains);

                        if (count($patientTokens) > 1) {
                            $subQuery->orWhere(function (Builder $tokenQuery) use ($patientTokens) {
                                foreach ($patientTokens as $token) {
                                    $tokenContains = '%'.$token.'%';
                                    $tokenQuery->where(function (Builder $fieldQuery) use ($tokenContains) {
                                        $fieldQuery->where('student_id', 'like', $tokenContains)
                                            ->orWhere('first_name', 'like', $tokenContains)
                                            ->orWhere('middle_name', 'like', $tokenContains)
                                            ->orWhere('last_name', 'like', $tokenContains);
                                    });
                                }
                            });
                        }
                    });
                })
                ->orderBy('last_name')
                ->orderBy('id')
                ->limit(10)
                ->get();

            $payload['staff'] = Staff::query()
                ->select(['id', 'staff_id', 'first_name', 'middle_name', 'last_name', 'department'])
                ->when($patientSearch !== '', function (Builder $query) use ($patientSearch, $patientTokens) {
                    $contains = '%'.$patientSearch.'%';
                    $query->where(function (Builder $subQuery) use ($contains, $patientTokens) {
                        $subQuery->where('staff_id', 'like', $contains)
                            ->orWhere('first_name', 'like', $contains)
                            ->orWhere('middle_name', 'like', $contains)
                            ->orWhere('last_name', 'like', $contains);

                        if (count($patientTokens) > 1) {
                            $subQuery->orWhere(function (Builder $tokenQuery) use ($patientTokens) {
                                foreach ($patientTokens as $token) {
                                    $tokenContains = '%'.$token.'%';
                                    $tokenQuery->where(function (Builder $fieldQuery) use ($tokenContains) {
                                        $fieldQuery->where('staff_id', 'like', $tokenContains)
                                            ->orWhere('first_name', 'like', $tokenContains)
                                            ->orWhere('middle_name', 'like', $tokenContains)
                                            ->orWhere('last_name', 'like', $tokenContains);
                                    });
                                }
                            });
                        }
                    });
                })
                ->orderBy('last_name')
                ->orderBy('id')
                ->limit(10)
                ->get();

            $payload['faculties'] = Faculty::query()
                ->select(['id', 'employee_id', 'first_name', 'middle_name', 'last_name', 'department'])
                ->when($patientSearch !== '', function (Builder $query) use ($patientSearch, $patientTokens) {
                    $contains = '%'.$patientSearch.'%';
                    $query->where(function (Builder $subQuery) use ($contains, $patientTokens) {
                        $subQuery->where('employee_id', 'like', $contains)
                            ->orWhere('first_name', 'like', $contains)
                            ->orWhere('middle_name', 'like', $contains)
                            ->orWhere('last_name', 'like', $contains);

                        if (count($patientTokens) > 1) {
                            $subQuery->orWhere(function (Builder $tokenQuery) use ($patientTokens) {
                                foreach ($patientTokens as $token) {
                                    $tokenContains = '%'.$token.'%';
                                    $tokenQuery->where(function (Builder $fieldQuery) use ($tokenContains) {
                                        $fieldQuery->where('employee_id', 'like', $tokenContains)
                                            ->orWhere('first_name', 'like', $tokenContains)
                                            ->orWhere('middle_name', 'like', $tokenContains)
                                            ->orWhere('last_name', 'like', $tokenContains);
                                    });
                                }
                            });
                        }
                    });
                })
                ->orderBy('last_name')
                ->orderBy('id')
                ->limit(10)
                ->get();
        }

        if (! $request->has('patient_search')) {
            $today = \Carbon\Carbon::today()->toDateString();
            $soonThreshold = \Carbon\Carbon::today()->addDays(3)->toDateString();
            $nearThreshold = \Carbon\Carbon::today()->addDays(30)->toDateString();

            $payload['medicines'] = Medicine::query()
                ->select(['id', 'medicine_name', 'treatment_type', 'unit', 'stock', 'minimum_stock', 'expiration_date'])
                ->with(['activeBatches' => function ($q) use ($today) {
                    $q->where('current_quantity', '>', 0)
                        ->where(function ($sub) use ($today) {
                            $sub->whereNull('expiration_date')->orWhere('expiration_date', '>=', $today);
                        })
                        ->orderBy('expiration_date', 'asc')
                        ->orderBy('date_added', 'asc');
                }])
                ->when($medicineSearch !== '', fn (Builder $query) => $query->where('medicine_name', 'like', '%'.$medicineSearch.'%'))
                ->where(function (Builder $query) use ($today) {
                    $query->whereNull('expiration_date')
                        ->orWhere('expiration_date', '>=', $today);
                })
                ->where('stock', '>', 0)
                ->orderBy('medicine_name')
                ->limit(50)
                ->get()
                ->map(function ($med) use ($today, $soonThreshold, $nearThreshold) {
                    $fefoBatch = $med->activeBatches->first();
                    $expDate = $fefoBatch?->expiration_date?->toDateString() ?? ($med->expiration_date ? \Carbon\Carbon::parse($med->expiration_date)->toDateString() : null);

                    $status = 'Good';
                    $days = null;
                    if ($expDate) {
                        $days = (int) \Carbon\Carbon::today()->diffInDays(\Carbon\Carbon::parse($expDate), false);
                        if ($days < 0) {
                            $status = 'Expired';
                        } elseif ($days <= 30) {
                            $status = 'Expiring Soon';
                        } elseif ($days <= 90) {
                            $status = 'Near Expiration';
                        } else {
                            $status = 'Good';
                        }
                    }

                    return [
                        'id' => $med->id,
                        'medicine_name' => $med->medicine_name,
                        'treatment_type' => $med->treatment_type,
                        'unit' => $med->unit,
                        'stock' => $med->stock,
                        'minimum_stock' => $med->minimum_stock,
                        'expiration_date' => $expDate,
                        'days_until_expiration' => $days,
                        'expiration_status' => $status,
                        'fefo_batch_number' => $fefoBatch?->batch_number ?? ($expDate ? 'BATCH-DEFAULT' : 'N/A'),
                        'fefo_batch_quantity' => $fefoBatch?->current_quantity ?? $med->stock,
                        'active_batches' => $med->activeBatches->map(fn ($b) => [
                            'id' => $b->id,
                            'batch_number' => $b->batch_number,
                            'current_quantity' => $b->current_quantity,
                            'expiration_date' => $b->expiration_date?->toDateString(),
                            'status' => $b->status,
                            'days_until_expiration' => $b->days_until_expiration,
                        ]),
                    ];
                });
        }

        if (! $request->has('patient_search') && ! $request->has('medicine_search')) {
            $payload['nurses'] = User::query()
                ->select(['id', 'name', 'email', 'role'])
                ->orderBy('name')
                ->get();
            $payload['totals'] = [
                'students' => Student::count(),
                'staff' => Staff::count(),
                'faculties' => Faculty::count(),
            ];
        }

        return response()->json($payload);
    }

    /**
     * Requirement: Instant Patient Identification & Medical History Lookup by ID Number
     * Automatically retrieves full patient profile and previous consultation history.
     */
    public function lookupPatient(Request $request)
    {
        $id = trim((string) ($request->query('id') ?? $request->query('student_id') ?? $request->query('query') ?? ''));

        if ($id === '') {
            return response()->json([
                'found' => false,
                'message' => 'Please provide an ID number to search.',
            ], 400);
        }

        // 1. Search Students by student_id
        $student = Student::where('student_id', $id)
            ->with(['clinicVisits' => function ($q) {
                $q->with(['nurse:id,name', 'medicine:id,medicine_name,unit'])
                    ->orderBy('visit_date', 'desc')
                    ->orderBy('id', 'desc');
            }])
            ->first();

        if ($student) {
            $visits = $student->clinicVisits;
            $totalVisits = $visits->count();
            $lastVisit = $visits->first();

            $age = null;
            if ($student->birth_date) {
                try {
                    $age = \Carbon\Carbon::parse($student->birth_date)->age;
                } catch (\Throwable) {}
            }

            return response()->json([
                'found' => true,
                'type' => 'student',
                'patient' => [
                    'id' => $student->id,
                    'student_id' => $student->student_id,
                    'identifier' => $student->student_id,
                    'first_name' => $student->first_name,
                    'middle_name' => $student->middle_name,
                    'last_name' => $student->last_name,
                    'full_name' => trim("{$student->first_name} {$student->middle_name} {$student->last_name}"),
                    'name' => trim("{$student->first_name} {$student->middle_name} {$student->last_name}"),
                    'course' => $student->course,
                    'year_level' => $student->year_level,
                    'section' => $student->section,
                    'sex' => $student->sex,
                    'birth_date' => $student->birth_date ? \Carbon\Carbon::parse($student->birth_date)->toDateString() : null,
                    'age' => $age,
                    'contact_number' => $student->contact_number,
                    'address' => $student->address,
                    'department' => $student->department ?? $student->course,
                    'total_visits' => $totalVisits,
                    'last_visit_date' => $lastVisit ? $lastVisit->visit_date : null,
                    'medical_history' => $visits->take(10)->map(function ($v) {
                        return [
                            'id' => $v->id,
                            'visit_date' => $v->visit_date,
                            'reason' => $v->reason,
                            'symptoms' => $v->symptoms,
                            'temperature' => $v->temperature,
                            'blood_pressure' => $v->blood_pressure,
                            'assessment' => $v->assessment,
                            'treatment' => $v->treatment,
                            'medicine_name' => $v->medicine ? $v->medicine->medicine_name : $v->medicine_name,
                            'medicine_quantity' => $v->medicine_quantity,
                            'medicine_unit' => $v->medicine ? $v->medicine->unit : null,
                            'nurse_name' => $v->nurse ? $v->nurse->name : null,
                            'remarks' => $v->remarks,
                        ];
                    }),
                ],
            ]);
        }

        // 2. Search Faculty by employee_id
        $faculty = Faculty::where('employee_id', $id)
            ->with(['clinicVisits' => function ($q) {
                $q->with(['nurse:id,name', 'medicine:id,medicine_name,unit'])
                    ->orderBy('visit_date', 'desc')
                    ->orderBy('id', 'desc');
            }])
            ->first();

        if ($faculty) {
            $visits = $faculty->clinicVisits;
            $totalVisits = $visits->count();
            $lastVisit = $visits->first();

            $age = null;
            if ($faculty->birth_date) {
                try {
                    $age = \Carbon\Carbon::parse($faculty->birth_date)->age;
                } catch (\Throwable) {}
            }

            return response()->json([
                'found' => true,
                'type' => 'faculty',
                'patient' => [
                    'id' => $faculty->id,
                    'employee_id' => $faculty->employee_id,
                    'student_id' => $faculty->employee_id,
                    'identifier' => $faculty->employee_id,
                    'first_name' => $faculty->first_name,
                    'middle_name' => $faculty->middle_name,
                    'last_name' => $faculty->last_name,
                    'full_name' => trim("{$faculty->first_name} {$faculty->middle_name} {$faculty->last_name}"),
                    'name' => trim("{$faculty->first_name} {$faculty->middle_name} {$faculty->last_name}"),
                    'position' => $faculty->position,
                    'department' => $faculty->department,
                    'course' => $faculty->department,
                    'year_level' => $faculty->position,
                    'section' => null,
                    'sex' => $faculty->sex,
                    'birth_date' => $faculty->birth_date ? \Carbon\Carbon::parse($faculty->birth_date)->toDateString() : null,
                    'age' => $age,
                    'contact_number' => $faculty->contact_number,
                    'address' => $faculty->address,
                    'total_visits' => $totalVisits,
                    'last_visit_date' => $lastVisit ? $lastVisit->visit_date : null,
                    'medical_history' => $visits->take(10)->map(function ($v) {
                        return [
                            'id' => $v->id,
                            'visit_date' => $v->visit_date,
                            'reason' => $v->reason,
                            'symptoms' => $v->symptoms,
                            'temperature' => $v->temperature,
                            'blood_pressure' => $v->blood_pressure,
                            'assessment' => $v->assessment,
                            'treatment' => $v->treatment,
                            'medicine_name' => $v->medicine ? $v->medicine->medicine_name : $v->medicine_name,
                            'medicine_quantity' => $v->medicine_quantity,
                            'medicine_unit' => $v->medicine ? $v->medicine->unit : null,
                            'nurse_name' => $v->nurse ? $v->nurse->name : null,
                            'remarks' => $v->remarks,
                        ];
                    }),
                ],
            ]);
        }

        // 3. Search Staff by staff_id
        $staff = Staff::where('staff_id', $id)
            ->with(['clinicVisits' => function ($q) {
                $q->with(['nurse:id,name', 'medicine:id,medicine_name,unit'])
                    ->orderBy('visit_date', 'desc')
                    ->orderBy('id', 'desc');
            }])
            ->first();

        if ($staff) {
            $visits = $staff->clinicVisits;
            $totalVisits = $visits->count();
            $lastVisit = $visits->first();

            $age = null;
            if ($staff->birth_date) {
                try {
                    $age = \Carbon\Carbon::parse($staff->birth_date)->age;
                } catch (\Throwable) {}
            }

            return response()->json([
                'found' => true,
                'type' => 'staff',
                'patient' => [
                    'id' => $staff->id,
                    'staff_id' => $staff->staff_id,
                    'student_id' => $staff->staff_id,
                    'identifier' => $staff->staff_id,
                    'first_name' => $staff->first_name,
                    'middle_name' => $staff->middle_name,
                    'last_name' => $staff->last_name,
                    'full_name' => trim("{$staff->first_name} {$staff->middle_name} {$staff->last_name}"),
                    'name' => trim("{$staff->first_name} {$staff->middle_name} {$staff->last_name}"),
                    'position' => $staff->position,
                    'department' => $staff->department,
                    'course' => $staff->department,
                    'year_level' => $staff->position,
                    'section' => null,
                    'sex' => $staff->sex,
                    'birth_date' => $staff->birth_date ? \Carbon\Carbon::parse($staff->birth_date)->toDateString() : null,
                    'age' => $age,
                    'contact_number' => $staff->contact_number,
                    'address' => $staff->address,
                    'total_visits' => $totalVisits,
                    'last_visit_date' => $lastVisit ? $lastVisit->visit_date : null,
                    'medical_history' => $visits->take(10)->map(function ($v) {
                        return [
                            'id' => $v->id,
                            'visit_date' => $v->visit_date,
                            'reason' => $v->reason,
                            'symptoms' => $v->symptoms,
                            'temperature' => $v->temperature,
                            'blood_pressure' => $v->blood_pressure,
                            'assessment' => $v->assessment,
                            'treatment' => $v->treatment,
                            'medicine_name' => $v->medicine ? $v->medicine->medicine_name : $v->medicine_name,
                            'medicine_quantity' => $v->medicine_quantity,
                            'medicine_unit' => $v->medicine ? $v->medicine->unit : null,
                            'nurse_name' => $v->nurse ? $v->nurse->name : null,
                            'remarks' => $v->remarks,
                        ];
                    }),
                ],
            ]);
        }

        return response()->json([
            'found' => false,
            'searched_id' => $id,
            'message' => "No existing patient record found with ID '{$id}'.",
        ]);
    }

    private function normalizeTemperature(?string $temp): ?string
    {
        if ($temp === null) {
            return null;
        }

        $trimmed = trim($temp);
        if ($trimmed === '' || $trimmed === '-') {
            return null;
        }

        // Check if Fahrenheit indicated by 'f' or '°f' or value > 50 without 'c'
        $isFahrenheit = preg_match('/f/i', $trimmed) || (! preg_match('/c/i', $trimmed) && (float) preg_replace('/[^0-9.]/', '', $trimmed) > 50);

        // Extract numeric part
        if (preg_match('/(\d+(?:\.\d+)?)/', $trimmed, $matches)) {
            $val = (float) $matches[1];
            if ($isFahrenheit) {
                $val = ($val - 32) * (5 / 9);
            }
            return number_format(round($val, 1), 1, '.', '');
        }

        return $trimmed;
    }

    private function resolveDepartment(array $validated): ?string
    {
        $dept = trim($validated['department'] ?? '');
        if ($dept !== '') {
            return $dept;
        }

        if (! empty($validated['faculty_id'])) {
            $faculty = Faculty::find($validated['faculty_id']);
            if ($faculty && ! empty($faculty->department)) {
                return trim($faculty->department);
            }
        }

        if (! empty($validated['staff_id'])) {
            $staff = Staff::find($validated['staff_id']);
            if ($staff && ! empty($staff->department)) {
                return trim($staff->department);
            }
        }

        if (! empty($validated['student_id'])) {
            $student = Student::find($validated['student_id']);
            if ($student && ! empty($student->course)) {
                return $this->mapCourseToDepartment($student->course);
            }
        }

        return null;
    }

    private function mapCourseToDepartment(string $course): string
    {
        $c = strtoupper(trim($course));
        return match ($c) {
            'BSIT' => 'College of Information Technology (BSIT)',
            'BSBA' => 'College of Business Administration (BSBA)',
            'BSHM' => 'College of Hospitality Management (BSHM)',
            'BSED' => 'College of Education (BSED)',
            'BSCRIM' => 'College of Criminal Justice Education (BSCRIM)',
            'MIDWIFERY' => 'College of Nursing & Midwifery',
            'BLIS' => 'Department of Library and Information Science (BLIS)',
            default => $course,
        };
    }

    protected function resolveRecipientData(array $validated): array
    {
        if (! empty($validated['student_id'])) {
            $student = Student::find($validated['student_id']);
            return [
                'type' => 'student',
                'id' => $student?->student_id ?? (string) $validated['student_id'],
                'name' => $student ? "{$student->first_name} {$student->last_name}" : 'Student',
                'notes' => 'Dispensed via Clinic Visit',
            ];
        }

        if (! empty($validated['faculty_id'])) {
            $faculty = Faculty::find($validated['faculty_id']);
            return [
                'type' => 'faculty',
                'id' => $faculty?->employee_id ?? (string) $validated['faculty_id'],
                'name' => $faculty ? "{$faculty->first_name} {$faculty->last_name}" : 'Faculty',
                'notes' => 'Dispensed via Clinic Visit',
            ];
        }

        if (! empty($validated['staff_id'])) {
            $staff = Staff::find($validated['staff_id']);
            return [
                'type' => 'staff',
                'id' => $staff?->staff_id ?? $staff?->employee_id ?? (string) $validated['staff_id'],
                'name' => $staff ? "{$staff->first_name} {$staff->last_name}" : 'Staff',
                'notes' => 'Dispensed via Clinic Visit',
            ];
        }

        return [
            'type' => 'other',
            'id' => null,
            'name' => 'Walk-in Patient',
            'notes' => 'Dispensed via Clinic Visit',
        ];
    }
}
