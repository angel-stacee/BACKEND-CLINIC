<?php

namespace App\Services;

use App\Models\ClinicNotification;
use App\Models\ClinicVisit;
use App\Models\Medicine;
use App\Models\MedicationSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    /**
     * Run all automatic checks and synchronize notifications with actual database state.
     */
    public function syncAll(?User $currentUser = null): void
    {
        $today = Carbon::today()->toDateString();

        $this->syncLowStockAlerts();
        $this->syncExpiredMedicineAlerts($today);
        $this->syncMedicationReminders($today);
        $this->syncNursePatientAlerts();
    }

    /**
     * 1. Low Medicine Stock Alerts
     */
    public function syncLowStockAlerts(): void
    {
        // 1a. Find all medicines currently at or below minimum_stock
        $lowMedicines = Medicine::query()
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereColumn('stock', '<=', 'minimum_stock')
                    ->orWhere(function ($q) {
                        $q->whereNull('minimum_stock')
                          ->where('stock', '<=', 5);
                    });
            })
            ->get();

        $activeLowMedIds = $lowMedicines->pluck('id')->toArray();

        foreach ($lowMedicines as $med) {
            $key = "low_stock_med_{$med->id}_active";
            $threshold = $med->minimum_stock ?? 5;

            // Check if active alert already exists
            $existing = ClinicNotification::where('unique_key', $key)->first();

            if (! $existing) {
                ClinicNotification::create([
                    'category' => 'low_stock',
                    'type' => 'warning',
                    'title' => "Low Stock Alert: {$med->medicine_name}",
                    'description' => "Only {$med->stock} {$med->unit} remaining (minimum threshold: {$threshold}).",
                    'metadata' => [
                        'medicine_id' => $med->id,
                        'medicine_name' => $med->medicine_name,
                        'stock' => $med->stock,
                        'minimum_stock' => $threshold,
                    ],
                    'unique_key' => $key,
                    'is_read' => false,
                ]);
            } else {
                // Keep description updated with latest count if stock changed while still low
                $existing->update([
                    'description' => "Only {$med->stock} {$med->unit} remaining (minimum threshold: {$threshold}).",
                ]);
            }
        }

        // 1b. If any medicine was restocked above minimum_stock, clear its active alert
        // This ensures: "Generate a new alert if the medicine returns to normal stock and becomes low again."
        $restockedMedicines = Medicine::query()
            ->whereNull('deleted_at')
            ->whereColumn('stock', '>', 'minimum_stock')
            ->get();

        foreach ($restockedMedicines as $med) {
            $key = "low_stock_med_{$med->id}_active";
            ClinicNotification::where('unique_key', $key)->delete();
        }
    }

    /**
     * 2. Expired Medicine Alerts
     */
    public function syncExpiredMedicineAlerts(string $today): void
    {
        $expiredMedicines = Medicine::query()
            ->whereNull('deleted_at')
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<=', $today)
            ->get();

        foreach ($expiredMedicines as $med) {
            $expDateStr = Carbon::parse($med->expiration_date)->format('Y-m-d');
            $expFormatted = Carbon::parse($med->expiration_date)->format('M d, Y');
            $key = "expired_med_{$med->id}_{$expDateStr}";

            $exists = ClinicNotification::where('unique_key', $key)->exists();

            if (! $exists) {
                ClinicNotification::create([
                    'category' => 'expired_medicine',
                    'type' => 'danger',
                    'title' => "Expired Medicine Alert: {$med->medicine_name}",
                    'description' => "{$med->medicine_name} expired on {$expFormatted}. Please review and remove from available dispensary.",
                    'metadata' => [
                        'medicine_id' => $med->id,
                        'medicine_name' => $med->medicine_name,
                        'expiration_date' => $expDateStr,
                    ],
                    'unique_key' => $key,
                    'is_read' => false,
                ]);
            }
        }
    }

    /**
     * 3. Patient Medication Reminders
     * Only triggers when a valid medication schedule exists in the database.
     */
    public function syncMedicationReminders(string $today): void
    {
        $activeSchedules = MedicationSchedule::with(['medicine', 'nurse'])
            ->where('status', 'active')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->get();

        foreach ($activeSchedules as $sched) {
            $key = "med_reminder_{$sched->id}_{$today}";
            $exists = ClinicNotification::where('unique_key', $key)->exists();

            if (! $exists) {
                $medName = $sched->medicine ? $sched->medicine->medicine_name : 'prescribed medicine';
                $time = $sched->scheduled_time ?: 'scheduled time';

                ClinicNotification::create([
                    'user_id' => $sched->nurse_id, // Assigned nurse or broadcast if null
                    'category' => 'medication_reminder',
                    'type' => 'info',
                    'title' => "Medication Reminder: {$sched->patient_name}",
                    'description' => "It's time for {$sched->patient_name} to take {$medName} ({$sched->dosage}, {$sched->frequency}) scheduled at {$time}.",
                    'metadata' => [
                        'schedule_id' => $sched->id,
                        'patient_name' => $sched->patient_name,
                        'patient_type' => $sched->patient_type,
                        'medicine_id' => $sched->medicine_id,
                        'scheduled_time' => $time,
                    ],
                    'unique_key' => $key,
                    'is_read' => false,
                ]);
            }
        }
    }

    /**
     * 4. Nurse Notifications (Patient Attention)
     */
    public function syncNursePatientAlerts(): void
    {
        // Query recent visits in last 48 hours that have an assigned nurse
        $recentVisits = ClinicVisit::with(['student', 'faculty', 'staff', 'nurse'])
            ->whereNotNull('nurse_id')
            ->where('created_at', '>=', Carbon::now()->subDays(2))
            ->get();

        foreach ($recentVisits as $visit) {
            $key = "nurse_assignment_visit_{$visit->id}";
            $exists = ClinicNotification::where('unique_key', $key)->exists();

            if (! $exists) {
                $patientName = 'Patient';
                $patientType = 'Patient';

                if ($visit->student) {
                    $patientName = trim($visit->student->first_name . ' ' . $visit->student->last_name);
                    $patientType = 'Student';
                } elseif ($visit->faculty) {
                    $patientName = trim($visit->faculty->first_name . ' ' . $visit->faculty->last_name);
                    $patientType = 'Faculty';
                } elseif ($visit->staff) {
                    $patientName = trim($visit->staff->first_name . ' ' . $visit->staff->last_name);
                    $patientType = 'Staff';
                }

                $reason = $visit->reason ?: 'clinical consultation';

                ClinicNotification::create([
                    'user_id' => $visit->nurse_id,
                    'category' => 'patient_attention',
                    'type' => 'info',
                    'title' => "Patient Assigned: {$patientName}",
                    'description' => "Please attend to {$patientName} ({$patientType}) for {$reason}. Consultation recorded on {$visit->visit_date}.",
                    'metadata' => [
                        'visit_id' => $visit->id,
                        'patient_name' => $patientName,
                        'patient_type' => $patientType,
                        'nurse_id' => $visit->nurse_id,
                    ],
                    'unique_key' => $key,
                    'is_read' => false,
                ]);
            }
        }
    }
}
