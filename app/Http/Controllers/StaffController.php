<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($validated['search'] ?? '');

        $staff = Staff::query()
            ->select([
                'id', 'staff_id', 'first_name', 'middle_name', 'last_name',
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
                    $subQuery->where('staff_id', 'like', $contains)
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
                                    $fieldQuery->where('staff_id', 'like', $tokenContains)
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

        return response()->json($staff);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'staff_id' => 'required|string|max:255|unique:staff,staff_id',
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
            'staff_id.unique' => "Staff ID ':input' is already in use by an existing staff member. Please enter a different ID.",
            'contact_number.regex' => 'Contact number must be an 11-digit number starting with 09 (e.g. 09532812400).',
        ]);

        $validated['sex'] = !empty($validated['sex']) ? $validated['sex'] : 'Male';
        $validated['birth_date'] = !empty($validated['birth_date']) ? $validated['birth_date'] : '2000-01-01';
        $validated['contact_number'] = !empty($validated['contact_number']) ? $validated['contact_number'] : '09000000000';
        $validated['address'] = !empty($validated['address']) ? $validated['address'] : 'Tagoloan, Misamis Oriental';

        $staffId = trim($validated['staff_id']);
        if ($student = Student::where('student_id', $staffId)->first()) {
            $name = trim("{$student->first_name} {$student->last_name}");
            throw ValidationException::withMessages([
                'staff_id' => ["The ID '{$staffId}' is already in use by student {$name}. Please enter a different ID."],
            ]);
        }
        if ($faculty = Faculty::where('employee_id', $staffId)->first()) {
            $name = trim("{$faculty->first_name} {$faculty->last_name}");
            throw ValidationException::withMessages([
                'staff_id' => ["The ID '{$staffId}' is already in use by faculty member {$name}. Please enter a different ID."],
            ]);
        }

        $staff = Staff::create($validated);

        return response()->json($staff, 201);
    }

    public function show(Staff $staff)
    {
        return response()->json($staff);
    }

    public function update(
        Request $request,
        Staff $staff
    ) {
        $validated = $request->validate([
            'staff_id' => 'required|string|max:255|unique:staff,staff_id,'.$staff->id,
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
            'staff_id.unique' => "Staff ID ':input' is already in use by an existing staff member. Please enter a different ID.",
            'contact_number.regex' => 'Contact number must be an 11-digit number starting with 09 (e.g. 09532812400).',
        ]);

        if (array_key_exists('sex', $validated) && empty($validated['sex'])) {
            $validated['sex'] = $staff->sex ?: 'Male';
        }
        if (array_key_exists('birth_date', $validated) && empty($validated['birth_date'])) {
            $validated['birth_date'] = $staff->birth_date ?: '2000-01-01';
        }
        if (array_key_exists('contact_number', $validated) && empty($validated['contact_number'])) {
            $validated['contact_number'] = $staff->contact_number ?: '09000000000';
        }
        if (array_key_exists('address', $validated) && empty($validated['address'])) {
            $validated['address'] = $staff->address ?: 'Tagoloan, Misamis Oriental';
        }

        $staffId = trim($validated['staff_id']);
        if ($student = Student::where('student_id', $staffId)->first()) {
            $name = trim("{$student->first_name} {$student->last_name}");
            throw ValidationException::withMessages([
                'staff_id' => ["The ID '{$staffId}' is already in use by student {$name}. Please enter a different ID."],
            ]);
        }
        if ($faculty = Faculty::where('employee_id', $staffId)->first()) {
            $name = trim("{$faculty->first_name} {$faculty->last_name}");
            throw ValidationException::withMessages([
                'staff_id' => ["The ID '{$staffId}' is already in use by faculty member {$name}. Please enter a different ID."],
            ]);
        }

        $staff->update($validated);

        return response()->json($staff);
    }

    public function destroy(Staff $staff)
    {
        $staff->delete();

        return response()->json([
            'message' => 'Staff/Faculty deleted successfully',
        ]);
    }

    public function medicalHistory(Staff $staff)
    {
        $visits = $staff->clinicVisits()
            ->with(['nurse:id,name', 'medicine:id,medicine_name,unit'])
            ->orderBy('visit_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $staffData = [
            'id' => $staff->id,
            'staff_id' => $staff->staff_id,
            'patient_id' => $staff->staff_id,
            'first_name' => $staff->first_name,
            'middle_name' => $staff->middle_name,
            'last_name' => $staff->last_name,
            'full_name' => trim("{$staff->first_name} {$staff->middle_name} {$staff->last_name}"),
            'position' => $staff->position,
            'department' => $staff->department ?? 'N/A',
            'sex' => $staff->sex,
            'birth_date' => $staff->birth_date,
            'contact_number' => $staff->contact_number,
            'address' => $staff->address,
        ];

        return response()->json([
            'patient_type' => 'staff',
            'staff' => $staffData,
            'patient' => $staffData,
            'total_visits' => $visits->count(),
            'generated_at' => now()->toIso8601String(),
            'visits' => $visits->map(function ($visit) use ($staff) {
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
                    'department' => $staff->department ?? 'N/A',
                    'nurse' => $visit->nurse ? $visit->nurse->name : 'N/A',
                    'medicine_name' => $visit->medicine ? $visit->medicine->medicine_name : null,
                    'medicine_unit' => $visit->medicine ? $visit->medicine->unit : null,
                    'medicine_quantity' => $visit->medicine_quantity,
                ];
            }),
        ]);
    }
}
