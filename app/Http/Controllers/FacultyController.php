<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FacultyController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($validated['search'] ?? '');

        $faculty = Faculty::query()
            ->select([
                'id', 'employee_id', 'first_name', 'middle_name', 'last_name',
                'position', 'department', 'sex', 'birth_date',
                'contact_number', 'address', 'created_at', 'updated_at',
            ])
            ->when($search !== '', function (Builder $query) use ($search) {
                $contains = '%'.$search.'%';
                $tokens = array_values(array_filter(
                    preg_split('/[\s,]+/', $search),
                    fn ($token) => $token !== '' && $token !== '-'
                ));

                $query->where(function (Builder $subQuery) use ($contains, $tokens) {
                    $subQuery->where('employee_id', 'like', $contains)
                        ->orWhere('first_name', 'like', $contains)
                        ->orWhere('middle_name', 'like', $contains)
                        ->orWhere('last_name', 'like', $contains)
                        ->orWhere('position', 'like', $contains)
                        ->orWhere('department', 'like', $contains);

                    if (count($tokens) > 1) {
                        $subQuery->orWhere(function (Builder $tokenQuery) use ($tokens) {
                            foreach ($tokens as $token) {
                                $tokenContains = '%'.$token.'%';
                                $tokenQuery->where(function (Builder $fieldQuery) use ($tokenContains) {
                                    $fieldQuery->where('employee_id', 'like', $tokenContains)
                                        ->orWhere('first_name', 'like', $tokenContains)
                                        ->orWhere('middle_name', 'like', $tokenContains)
                                        ->orWhere('last_name', 'like', $tokenContains)
                                        ->orWhere('position', 'like', $tokenContains)
                                        ->orWhere('department', 'like', $tokenContains);
                                });
                            }
                        });
                    }
                });
            })
            ->orderBy('last_name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return response()->json($faculty);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('faculties', 'employee_id')->whereNull('deleted_at'),
            ],
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'sex' => 'nullable|string|max:50',
            'birth_date' => 'nullable|date',
            'contact_number' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
            'address' => 'nullable|string|max:255',
        ], [
            'employee_id.unique' => "Faculty ID ':input' is already in use by an existing faculty member. Please enter a different ID.",
            'contact_number.regex' => 'Contact number must be an 11-digit number starting with 09 (e.g. 09532812400).',
        ]);

        $employeeId = trim($validated['employee_id']);
        if ($student = Student::where('student_id', $employeeId)->first()) {
            $name = trim("{$student->first_name} {$student->last_name}");
            throw ValidationException::withMessages([
                'employee_id' => ["The ID '{$employeeId}' is already in use by student {$name}. Please enter a different ID."],
            ]);
        }
        if ($staff = Staff::where('staff_id', $employeeId)->first()) {
            $name = trim("{$staff->first_name} {$staff->last_name}");
            throw ValidationException::withMessages([
                'employee_id' => ["The ID '{$employeeId}' is already in use by staff member {$name}. Please enter a different ID."],
            ]);
        }

        $faculty = Faculty::create($validated);

        return response()->json($faculty, 201);
    }

    public function show(Faculty $faculty)
    {
        // Lazy-generate QR token for faculty that pre-date the feature
        if (empty($faculty->qr_token)) {
            $faculty->qr_token = Faculty::generateQrToken();
            $faculty->saveQuietly();
        }

        return response()->json($faculty);
    }

    public function update(Request $request, Faculty $faculty)
    {
        $validated = $request->validate([
            'employee_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('faculties', 'employee_id')->ignore($faculty->id)->whereNull('deleted_at'),
            ],
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'sex' => 'nullable|string|max:50',
            'birth_date' => 'nullable|date',
            'contact_number' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
            'address' => 'nullable|string|max:255',
        ], [
            'employee_id.unique' => "Faculty ID ':input' is already in use by an existing faculty member. Please enter a different ID.",
            'contact_number.regex' => 'Contact number must be an 11-digit number starting with 09 (e.g. 09532812400).',
        ]);

        $employeeId = trim($validated['employee_id']);
        if ($student = Student::where('student_id', $employeeId)->first()) {
            $name = trim("{$student->first_name} {$student->last_name}");
            throw ValidationException::withMessages([
                'employee_id' => ["The ID '{$employeeId}' is already in use by student {$name}. Please enter a different ID."],
            ]);
        }
        if ($staff = Staff::where('staff_id', $employeeId)->first()) {
            $name = trim("{$staff->first_name} {$staff->last_name}");
            throw ValidationException::withMessages([
                'employee_id' => ["The ID '{$employeeId}' is already in use by staff member {$name}. Please enter a different ID."],
            ]);
        }

        $faculty->update($validated);

        return response()->json($faculty->fresh());
    }

    public function destroy(Faculty $faculty)
    {
        $faculty->delete();

        return response()->json([
            'message' => 'Faculty record moved to trash.',
            'id' => $faculty->id,
        ]);
    }

    public function regenerateQr(Faculty $faculty): \Illuminate\Http\JsonResponse
    {
        $newToken = Faculty::generateQrToken();
        $faculty->qr_token = $newToken;
        $faculty->saveQuietly();

        AuditLogService::record(
            'qr.regenerate',
            "Regenerated QR token for faculty #{$faculty->id}",
            'faculty',
            $faculty->id
        );

        return response()->json(['qr_token' => $newToken]);
    }

    public function trashed(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $query = Faculty::onlyTrashed();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('employee_id', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('position', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->get('per_page', 10)));
        return response()->json($query->orderByDesc('deleted_at')->paginate($perPage));
    }

    public function restore($id)
    {
        $faculty = Faculty::onlyTrashed()->findOrFail($id);
        $faculty->restore();

        return response()->json([
            'message' => 'Faculty record restored successfully.',
            'faculty' => $faculty,
        ]);
    }

    public function forceDelete($id)
    {
        $faculty = Faculty::onlyTrashed()->findOrFail($id);
        $faculty->forceDelete();

        return response()->json([
            'message' => 'Faculty record permanently deleted.',
        ]);
    }

    public function medicalHistory(Faculty $faculty)
    {
        $visits = $faculty->clinicVisits()
            ->with(['nurse:id,name', 'medicine:id,medicine_name,unit'])
            ->orderBy('visit_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $facultyData = [
            'id' => $faculty->id,
            'employee_id' => $faculty->employee_id,
            'patient_id' => $faculty->employee_id,
            'first_name' => $faculty->first_name,
            'middle_name' => $faculty->middle_name,
            'last_name' => $faculty->last_name,
            'full_name' => trim("{$faculty->first_name} {$faculty->middle_name} {$faculty->last_name}"),
            'position' => $faculty->position,
            'department' => $faculty->department ?? 'N/A',
            'sex' => $faculty->sex,
            'birth_date' => $faculty->birth_date,
            'contact_number' => $faculty->contact_number,
            'address' => $faculty->address,
        ];

        return response()->json([
            'patient_type' => 'faculty',
            'faculty' => $facultyData,
            'patient' => $facultyData,
            'total_visits' => $visits->count(),
            'generated_at' => now()->toIso8601String(),
            'visits' => $visits->map(function ($visit) use ($faculty) {
                return [
                    'id' => $visit->id,
                    'visit_date' => $visit->visit_date,
                    'reason' => $visit->reason,
                    'symptoms' => $visit->symptoms,
                    'temperature' => $visit->temperature,
                    'blood_pressure' => $visit->blood_pressure,
                    'assessment' => $visit->assessment,
                    'treatment' => $visit->treatment,
                    'remarks' => $visit->remarks,
                    'department' => $faculty->department ?? 'N/A',
                    'nurse' => $visit->nurse ? $visit->nurse->name : 'N/A',
                    'medicine_name' => $visit->medicine ? $visit->medicine->medicine_name : null,
                    'medicine_unit' => $visit->medicine ? $visit->medicine->unit : null,
                    'medicine_quantity' => $visit->medicine_quantity,
                ];
            }),
        ]);
    }
}
