<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
            'student_id' => 'required|string|max:255|unique:students,student_id',
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
            'student_id' => 'required|string|max:255|unique:students,student_id,'.$student->id,
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
            'message' => 'Student deleted successfully.',
        ]);
    }
}
