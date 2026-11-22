<?php

namespace App\Http\Controllers;

use App\Models\MedicationSchedule;
use App\Models\Student;
use App\Models\Faculty;
use App\Models\Staff;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MedicationScheduleController extends Controller
{
    /**
     * List active medication schedules.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MedicationSchedule::with(['medicine', 'nurse'])
            ->orderBy('status', 'asc') // active first
            ->orderBy('start_date', 'desc');

        if ($request->filled('patient_type')) {
            $query->where('patient_type', $request->patient_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Create a new medication schedule.
     */
    public function store(Request $request, NotificationService $notificationService): JsonResponse
    {
        $validated = $request->validate([
            'patient_type' => ['required', 'string', 'in:student,faculty,staff'],
            'student_id' => ['nullable', 'exists:students,id'],
            'faculty_id' => ['nullable', 'exists:faculties,id'],
            'staff_id' => ['nullable', 'exists:staff,id'],
            'medicine_id' => ['required', 'exists:medicines,id'],
            'dosage' => ['required', 'string', 'max:100'],
            'frequency' => ['required', 'string', 'max:100'],
            'scheduled_time' => ['required', 'string', 'max:50'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'nurse_id' => ['nullable', 'exists:users,id'],
        ]);

        // Resolve patient name
        $patientName = 'Patient';
        if ($validated['patient_type'] === 'student' && !empty($validated['student_id'])) {
            $s = Student::find($validated['student_id']);
            if ($s) $patientName = trim($s->first_name . ' ' . $s->last_name);
        } elseif ($validated['patient_type'] === 'faculty' && !empty($validated['faculty_id'])) {
            $f = Faculty::find($validated['faculty_id']);
            if ($f) $patientName = trim($f->first_name . ' ' . $f->last_name);
        } elseif ($validated['patient_type'] === 'staff' && !empty($validated['staff_id'])) {
            $st = Staff::find($validated['staff_id']);
            if ($st) $patientName = trim($st->first_name . ' ' . $st->last_name);
        }

        $validated['patient_name'] = $patientName;
        $validated['nurse_id'] = $validated['nurse_id'] ?? $request->user()?->id;

        $schedule = MedicationSchedule::create($validated);

        // Sync notifications immediately so reminder is ready
        $notificationService->syncAll();

        return response()->json([
            'status' => 'success',
            'message' => 'Medication schedule created successfully.',
            'data' => $schedule->load(['medicine', 'nurse']),
        ], 201);
    }

    /**
     * Update an existing schedule.
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        $schedule = MedicationSchedule::findOrFail($id);

        $validated = $request->validate([
            'dosage' => ['sometimes', 'string', 'max:100'],
            'frequency' => ['sometimes', 'string', 'max:100'],
            'scheduled_time' => ['sometimes', 'string', 'max:50'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', 'in:active,completed,cancelled'],
        ]);

        $schedule->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Medication schedule updated.',
            'data' => $schedule->load(['medicine', 'nurse']),
        ]);
    }

    /**
     * Delete or cancel a schedule.
     */
    public function destroy(int|string $id): JsonResponse
    {
        $schedule = MedicationSchedule::findOrFail($id);
        $schedule->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Medication schedule removed.',
        ]);
    }
}
