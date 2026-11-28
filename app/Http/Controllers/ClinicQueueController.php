<?php

namespace App\Http\Controllers;

use App\Models\ClinicQueue;
use App\Models\Faculty;
use App\Models\Staff;
use App\Models\Student;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'active');

        $query = ClinicQueue::query()
            ->orderByRaw("CASE priority WHEN 'Urgent' THEN 1 WHEN 'Follow-up' THEN 2 ELSE 3 END ASC")
            ->orderBy('checked_in_at', 'asc')
            ->orderBy('id', 'asc');

        if ($status === 'active') {
            $query->whereIn('status', ['Waiting', 'In Consultation']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $items = $query->limit(100)->get()->values()->map(function (ClinicQueue $q, int $idx) {
            return array_merge($q->toArray(), [
                'queue_position' => in_array($q->status, ['Waiting', 'In Consultation'], true) ? $idx + 1 : null,
            ]);
        });

        return response()->json([
            'data' => $items,
            'counts' => [
                'waiting' => ClinicQueue::where('status', 'Waiting')->count(),
                'in_consultation' => ClinicQueue::where('status', 'In Consultation')->count(),
                'completed_today' => ClinicQueue::where('status', 'Completed')
                    ->whereDate('completed_at', now()->toDateString())
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_type' => ['required', 'in:student,faculty,staff'],
            'patient_id' => ['required', 'integer'],
            'priority' => ['nullable', 'in:Routine,Urgent,Follow-up'],
            'chief_complaint' => ['nullable', 'string', 'max:255'],
        ]);

        $patient = match ($validated['patient_type']) {
            'student' => Student::findOrFail($validated['patient_id']),
            'faculty' => Faculty::findOrFail($validated['patient_id']),
            'staff' => Staff::findOrFail($validated['patient_id']),
        };

        $existingActive = ClinicQueue::where('patient_type', $validated['patient_type'])
            ->where('patient_id', $patient->id)
            ->whereIn('status', ['Waiting', 'In Consultation'])
            ->first();

        if ($existingActive) {
            return response()->json([
                'message' => 'This patient is already in the active clinic queue.',
                'queue_entry' => $existingActive,
            ], 422);
        }

        $identifier = match ($validated['patient_type']) {
            'student' => $patient->student_id,
            'faculty' => $patient->employee_id,
            'staff' => $patient->staff_id,
        };

        $department = $validated['patient_type'] === 'student'
            ? $patient->course
            : $patient->department;

        $entry = ClinicQueue::create([
            'patient_type' => $validated['patient_type'],
            'patient_id' => $patient->id,
            'patient_identifier' => $identifier,
            'patient_name' => trim("{$patient->first_name} {$patient->middle_name} {$patient->last_name}"),
            'department' => $department,
            'priority' => $validated['priority'] ?? 'Routine',
            'status' => 'Waiting',
            'chief_complaint' => $validated['chief_complaint'] ?? null,
            'created_by' => $request->user()?->id,
            'checked_in_at' => now(),
        ]);

        AuditLogService::record(
            'queue.checked_in',
            "Checked in {$entry->patient_type} #{$entry->patient_id} ({$entry->priority})",
            'clinic_queue',
            $entry->id,
            [],
            $request->user(),
            $request
        );

        return response()->json([
            'message' => 'Patient added to clinic queue.',
            'queue_entry' => $entry,
        ], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $entry = ClinicQueue::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'in:Waiting,In Consultation,Completed,Cancelled'],
            'clinic_visit_id' => ['nullable', 'integer', 'exists:clinic_visits,id'],
        ]);

        $updates = ['status' => $validated['status']];
        if ($validated['status'] === 'In Consultation' && ! $entry->called_at) {
            $updates['called_at'] = now();
        }
        if ($validated['status'] === 'Completed') {
            $updates['completed_at'] = now();
        }
        if (isset($validated['clinic_visit_id'])) {
            $updates['clinic_visit_id'] = $validated['clinic_visit_id'];
        }

        $entry->update($updates);

        return response()->json([
            'message' => "Queue status updated to {$validated['status']}.",
            'queue_entry' => $entry->fresh(),
        ]);
    }
}
