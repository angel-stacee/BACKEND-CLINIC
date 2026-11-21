<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentController extends Controller
{
    /**
     * Check if a patient ID is already in use across students, staff, or faculty.
     */
    public function checkId(Request $request)
    {
        $id = trim((string) $request->query('id', ''));
        if ($id === '') {
            return response()->json(['available' => true]);
        }

        $category = (string) $request->query('category', 'student');
        $excludeId = $request->query('exclude_id');

        // Check students
        $studentQuery = Student::where('student_id', $id);
        if ($category === 'student' && ! empty($excludeId)) {
            $studentQuery->where('id', '!=', $excludeId);
        }
        $existingStudent = $studentQuery->first();
        if ($existingStudent) {
            $name = trim("{$existingStudent->first_name} {$existingStudent->last_name}");

            return response()->json([
                'available' => false,
                'category' => 'student',
                'name' => $name,
                'message' => "Student ID '{$id}' is already in use by student {$name}. Please enter a different ID.",
            ]);
        }

        // Check staff
        $staffQuery = Staff::where('staff_id', $id);
        if ($category === 'staff' && ! empty($excludeId)) {
            $staffQuery->where('id', '!=', $excludeId);
        }
        $existingStaff = $staffQuery->first();
        if ($existingStaff) {
            $name = trim("{$existingStaff->first_name} {$existingStaff->last_name}");

            return response()->json([
                'available' => false,
                'category' => 'staff',
                'name' => $name,
                'message' => "Staff ID '{$id}' is already in use by staff member {$name}. Please enter a different ID.",
            ]);
        }

        // Check faculty
        $facultyQuery = Faculty::where('employee_id', $id);
        if ($category === 'faculty' && ! empty($excludeId)) {
            $facultyQuery->where('id', '!=', $excludeId);
        }
        $existingFaculty = $facultyQuery->first();
        if ($existingFaculty) {
            $name = trim("{$existingFaculty->first_name} {$existingFaculty->last_name}");

            return response()->json([
                'available' => false,
                'category' => 'faculty',
                'name' => $name,
                'message' => "Faculty ID '{$id}' is already in use by faculty member {$name}. Please enter a different ID.",
            ]);
        }

        return response()->json([
            'available' => true,
        ]);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($validated['search'] ?? '');

        $query = Student::query()
            ->select([
                'id',
                'student_id',
                'first_name',
                'middle_name',
                'last_name',
                'course',
                'year_level',
                'section',
                'sex',
                'birth_date',
                'contact_number',
                'address',
                'created_at',
                'updated_at',
            ])
            ->when($search !== '', function (Builder $query) use ($search) {
                $contains = '%'.$search.'%';
                $tokens = array_values(array_filter(
                    preg_split('/[\s,]+/', $search),
                    fn ($token) => $token !== '' && $token !== '-'
                ));

                $query->where(function (Builder $subQuery) use ($contains, $tokens) {
                    $subQuery->where('student_id', 'like', $contains)
                        ->orWhere('first_name', 'like', $contains)
                        ->orWhere('middle_name', 'like', $contains)
                        ->orWhere('last_name', 'like', $contains)
                        ->orWhere('course', 'like', $contains)
                        ->orWhere('year_level', 'like', $contains)
                        ->orWhere('section', 'like', $contains);

                    if (count($tokens) > 1) {
                        $subQuery->orWhere(function (Builder $tokenQuery) use ($tokens) {
                            foreach ($tokens as $token) {
                                $tokenContains = '%'.$token.'%';
                                $tokenQuery->where(function (Builder $fieldQuery) use ($tokenContains) {
                                    $fieldQuery->where('student_id', 'like', $tokenContains)
                                        ->orWhere('first_name', 'like', $tokenContains)
                                        ->orWhere('middle_name', 'like', $tokenContains)
                                        ->orWhere('last_name', 'like', $tokenContains)
                                        ->orWhere('course', 'like', $tokenContains)
                                        ->orWhere('year_level', 'like', $tokenContains)
                                        ->orWhere('section', 'like', $tokenContains);
                                });
                            }
                        });
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->json(
            $query->paginate($validated['per_page'] ?? 25)->withQueryString()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('students', 'student_id')->whereNull('deleted_at'),
            ],
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'course' => 'required|string|max:255',
            'year_level' => 'required|string|max:255',
            'section' => 'nullable|string|max:255',
            'sex' => 'required|string|max:50',
            'birth_date' => 'required|date',
            'contact_number' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'address' => 'required|string',
        ], [
            'student_id.unique' => "Student ID ':input' is already in use by an existing student. Please enter a different ID.",
            'contact_number.regex' => 'Contact number must be an 11-digit number starting with 09 (e.g. 09532812400).',
        ]);

        $studentId = trim($validated['student_id']);
        if ($staff = Staff::where('staff_id', $studentId)->first()) {
            $name = trim("{$staff->first_name} {$staff->last_name}");
            throw ValidationException::withMessages([
                'student_id' => ["The ID '{$studentId}' is already in use by staff member {$name}. Please enter a different ID."],
            ]);
        }
        if ($faculty = Faculty::where('employee_id', $studentId)->first()) {
            $name = trim("{$faculty->first_name} {$faculty->last_name}");
            throw ValidationException::withMessages([
                'student_id' => ["The ID '{$studentId}' is already in use by faculty member {$name}. Please enter a different ID."],
            ]);
        }

        $student = Student::create($validated);

        return response()->json($student, 201);
    }

    public function show(Student $student)
    {
        return response()->json(
            $student->load('clinicVisits.nurse')
        );
    }

    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
            'student_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('students', 'student_id')->ignore($student->id)->whereNull('deleted_at'),
            ],
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'course' => 'required|string|max:255',
            'year_level' => 'required|string|max:255',
            'section' => 'nullable|string|max:255',
            'sex' => 'required|string|max:50',
            'birth_date' => 'required|date',
            'contact_number' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'address' => 'required|string',
        ], [
            'student_id.unique' => "Student ID ':input' is already in use by an existing student. Please enter a different ID.",
            'contact_number.regex' => 'Contact number must be an 11-digit number starting with 09 (e.g. 09532812400).',
        ]);

        $studentId = trim($validated['student_id']);
        if ($staff = Staff::where('staff_id', $studentId)->first()) {
            $name = trim("{$staff->first_name} {$staff->last_name}");
            throw ValidationException::withMessages([
                'student_id' => ["The ID '{$studentId}' is already in use by staff member {$name}. Please enter a different ID."],
            ]);
        }
        if ($faculty = Faculty::where('employee_id', $studentId)->first()) {
            $name = trim("{$faculty->first_name} {$faculty->last_name}");
            throw ValidationException::withMessages([
                'student_id' => ["The ID '{$studentId}' is already in use by faculty member {$name}. Please enter a different ID."],
            ]);
        }

        $student->update($validated);

        return response()->json($student->fresh());
    }

    public function destroy(Student $student)
    {
        $student->delete();

        return response()->json([
            'message' => 'Student record moved to trash.',
            'id' => $student->id,
        ]);
    }

    public function trashed(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $query = Student::onlyTrashed();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('student_id', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('course', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->get('per_page', 10)));
        return response()->json($query->orderByDesc('deleted_at')->paginate($perPage));
    }

    public function restore($id)
    {
        $student = Student::onlyTrashed()->findOrFail($id);
        $student->restore();

        return response()->json([
            'message' => 'Student record restored successfully.',
            'student' => $student,
        ]);
    }

    public function forceDelete($id)
    {
        $student = Student::onlyTrashed()->findOrFail($id);
        $student->forceDelete();

        return response()->json([
            'message' => 'Student record permanently deleted.',
        ]);
    }

    public function medicalHistory(Student $student)
    {
        $visits = $student->clinicVisits()
            ->with(['nurse:id,name', 'medicine:id,medicine_name,unit'])
            ->orderBy('visit_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $courseDepartment = match (strtoupper(trim($student->course ?? ''))) {
            'BSIT' => 'College of Information Technology (BSIT)',
            'BSBA' => 'College of Business Administration (BSBA)',
            'BSHM' => 'College of Hospitality Management (BSHM)',
            'BSED' => 'College of Education (BSED)',
            'BSCRIM' => 'College of Criminal Justice Education (BSCRIM)',
            'MIDWIFERY' => 'College of Nursing & Midwifery',
            'BLIS' => 'Department of Library and Information Science (BLIS)',
            default => $student->course ?? 'N/A',
        };

        $studentData = [
            'id' => $student->id,
            'student_id' => $student->student_id,
            'patient_id' => $student->student_id,
            'first_name' => $student->first_name,
            'middle_name' => $student->middle_name,
            'last_name' => $student->last_name,
            'full_name' => trim("{$student->first_name} {$student->middle_name} {$student->last_name}"),
            'course' => $student->course,
            'year_level' => $student->year_level,
            'section' => $student->section,
            'department' => $courseDepartment,
            'sex' => $student->sex,
            'birth_date' => $student->birth_date,
            'contact_number' => $student->contact_number,
            'address' => $student->address,
        ];

        return response()->json([
            'patient_type' => 'student',
            'student' => $studentData,
            'patient' => $studentData,
            'total_visits' => $visits->count(),
            'generated_at' => now()->toIso8601String(),
            'visits' => $visits->map(function ($visit) use ($courseDepartment) {
                return [
                    'id' => $visit->id,
                    'visit_date' => $visit->visit_date ? substr((string) $visit->visit_date, 0, 10) : null,
                    'reason' => $visit->reason,
                    'symptoms' => $visit->symptoms,
                    'temperature' => $visit->temperature,
                    'blood_pressure' => $visit->blood_pressure,
                    'assessment' => $visit->assessment,
                    'treatment' => $visit->treatment,
                    'department' => $visit->department ?: $courseDepartment,
                    'medicine_id' => $visit->medicine_id,
                    'medicine_name' => $visit->medicine?->medicine_name,
                    'medicine_unit' => $visit->medicine?->unit,
                    'medicine_quantity' => $visit->medicine_quantity,
                    'nurse_name' => $visit->nurse?->name,
                    'remarks' => $visit->remarks,
                ];
            }),
        ]);
    }
}
