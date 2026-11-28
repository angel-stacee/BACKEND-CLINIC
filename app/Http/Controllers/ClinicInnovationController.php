<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClinicVisit;
use App\Models\Faculty;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineTransaction;
use App\Models\Staff;
use App\Models\Student;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class ClinicInnovationController extends Controller
{
    /**
     * Generate a signed opaque QR payload for an existing patient (no PII embedded).
     */
    public static function makeQrToken(string $type, int $id): string
    {
        $payload = "{$type}:{$id}";
        $sig = substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 16);

        return "TCMS1:{$payload}:{$sig}";
    }

    /**
     * A. QR Patient Identification — resolves signed QR token or institutional ID without creating duplicates.
     */
    public function qrResolve(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return response()->json(['message' => 'QR code or patient ID is required.'], 422);
        }

        $patient = null;
        $type = null;

        // 0. Opaque database qr_token (zero PII) — student, faculty, staff
        if ($s = Student::where('qr_token', $code)->first()) {
            $patient = $s;
            $type = 'student';
        } elseif ($f = Faculty::where('qr_token', $code)->first()) {
            $patient = $f;
            $type = 'faculty';
        } elseif ($st = Staff::where('qr_token', $code)->first()) {
            $patient = $st;
            $type = 'staff';
        }

        // 1. Signed token format: TCMS1:<type>:<id>:<sig>
        if (! $patient && str_starts_with($code, 'TCMS1:')) {
            $parts = explode(':', $code);
            if (count($parts) === 4) {
                [, $t, $pid, $sig] = $parts;
                $expected = substr(hash_hmac('sha256', "{$t}:{$pid}", (string) config('app.key')), 0, 16);
                if (! hash_equals($expected, $sig)) {
                    return response()->json(['message' => 'Invalid or tampered QR token.'], 422);
                }
                $type = $t;
                $id = (int) $pid;
                $patient = match ($type) {
                    'student' => Student::find($id),
                    'faculty' => Faculty::find($id),
                    'staff' => Staff::find($id),
                    default => null,
                };
            }
        }

        // 2. Fallback: raw institutional ID (student_id, employee_id, staff_id)
        if (! $patient) {
            if ($s = Student::where('student_id', $code)->first()) {
                $patient = $s;
                $type = 'student';
            } elseif ($f = Faculty::where('employee_id', $code)->first()) {
                $patient = $f;
                $type = 'faculty';
            } elseif ($st = Staff::where('staff_id', $code)->first()) {
                $patient = $st;
                $type = 'staff';
            }
        }

        if (! $patient || ! $type) {
            return response()->json([
                'found' => false,
                'message' => 'No registered patient matched this QR code.',
            ], 404);
        }

        AuditLogService::record(
            'qr.resolve',
            "Resolved {$type} patient #{$patient->id} via QR scan",
            $type,
            $patient->id,
            [],
            $request->user(),
            $request
        );

        $identifier = match ($type) {
            'student' => $patient->student_id,
            'faculty' => $patient->employee_id,
            'staff' => $patient->staff_id,
        };

        $department = match ($type) {
            'student' => $patient->course,
            default => $patient->department,
        };

        return response()->json([
            'found' => true,
            'patient_type' => $type,
            'qr_token' => ! empty($patient->qr_token)
                ? $patient->qr_token
                : self::makeQrToken($type, $patient->id),
            'patient' => [
                'id' => $patient->id,
                'identifier' => $identifier,
                'first_name' => $patient->first_name,
                'middle_name' => $patient->middle_name,
                'last_name' => $patient->last_name,
                'full_name' => trim("{$patient->first_name} {$patient->middle_name} {$patient->last_name}"),
                'department' => $department,
                'sex' => $patient->sex,
                'visit_count' => $patient->clinicVisits()->count(),
            ],
        ]);
    }

    /**
     * Send a patient's clinic QR code PNG to their email address.
     */
    public function sendQrEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_type' => 'required|in:student,faculty,staff',
            'patient_id' => 'required|integer',
            'email' => 'required|email|max:255',
            'qr_image' => 'required|string',
        ]);

        $type = $validated['patient_type'];
        $id = (int) $validated['patient_id'];
        $patient = match ($type) {
            'student' => Student::findOrFail($id),
            'faculty' => Faculty::findOrFail($id),
            'staff' => Staff::findOrFail($id),
        };

        $identifier = match ($type) {
            'student' => $patient->student_id,
            'faculty' => $patient->employee_id,
            'staff' => $patient->staff_id,
        };

        $fullName = trim("{$patient->first_name} {$patient->middle_name} {$patient->last_name}");
        $qrToken = $patient->qr_token ?: self::makeQrToken($type, $patient->id);

        if (! preg_match('/^data:image\/png;base64,(.+)$/', $validated['qr_image'], $matches)) {
            return response()->json(['message' => 'Invalid QR image format.'], 422);
        }

        $pngBinary = base64_decode($matches[1], true);
        if ($pngBinary === false || strlen($pngBinary) === 0) {
            return response()->json(['message' => 'Failed to decode QR image.'], 422);
        }

        $safeName = e($fullName);
        $safeId = e((string) $identifier);
        $safeRole = ucfirst($type);
        $safeToken = e($qrToken);
        $filename = "TCC-Clinic-QR-{$identifier}.png";
        $logoPath = public_path('images/tcc-logo.png');

        Mail::send([], [], function ($message) use ($validated, $fullName, $pngBinary, $filename, $logoPath, $safeName, $safeId, $safeRole, $safeToken) {
            $logoImgTag = '';
            if (is_file($logoPath)) {
                $logoCid = $message->embed($logoPath);
                $logoImgTag = "<img src=\"{$logoCid}\" alt=\"TCC Logo\" style=\"width: 56px; height: 56px; object-fit: contain; border-radius: 50%; background: #ffffff; padding: 4px; margin-bottom: 8px;\" />";
            }

            $html = <<<HTML
            <div style="font-family: system-ui, -apple-system, sans-serif; max-width: 520px; margin: 0 auto; border: 1px solid #e8dfd4; border-radius: 12px; overflow: hidden; background: #ffffff;">
                <div style="background: #70091f; color: #ffffff; padding: 20px 24px; text-align: center;">
                    {$logoImgTag}
                    <h2 style="margin: 0; font-size: 18px;">TCC CLINIC MANAGEMENT SYSTEM</h2>
                    <p style="margin: 4px 0 0; font-size: 12px; opacity: 0.9;">Tagoloan Community College Health Services</p>
                </div>
                <div style="padding: 24px; color: #1c1917;">
                    <p style="margin: 0 0 12px; font-size: 14px;">Hello <strong>{$safeName}</strong>,</p>
                    <p style="margin: 0 0 16px; font-size: 13px; color: #57534e; line-height: 1.5;">
                        Attached is your personal <strong>TCC Clinic QR Identification</strong> ({$safeRole} ID: <strong>{$safeId}</strong>).
                        Present this QR code to the clinic nurse during your visit for fast check-in.
                    </p>
                    <div style="background: #fcfaf6; border: 1px solid #e8dfd4; border-radius: 8px; padding: 12px 16px; font-size: 12px; color: #57534e;">
                        <div><strong>Token:</strong> <span style="font-family: monospace;">{$safeToken}</span></div>
                        <div style="margin-top: 4px; font-size: 11px; color: #15803d;">This QR code contains no personal or medical information.</div>
                    </div>
                </div>
            </div>
            HTML;

            $message->to($validated['email'], $fullName)
                ->subject('Your TCC Clinic QR Identification')
                ->html($html)
                ->attachData($pngBinary, $filename, ['mime' => 'image/png']);
        });

        AuditLogService::record(
            'qr.send_email',
            "Sent QR code email for {$type} #{$patient->id} to {$validated['email']}",
            $type,
            $patient->id,
            ['email' => $validated['email']],
            $request->user(),
            $request
        );

        return response()->json([
            'message' => "QR code sent to {$validated['email']}.",
        ]);
    }

    /**
     * B. Chronological Patient Medical Timeline.
     */
    public function patientTimeline(Request $request, string $type, int $id): JsonResponse
    {
        $patient = match ($type) {
            'student' => Student::findOrFail($id),
            'faculty' => Faculty::findOrFail($id),
            'staff' => Staff::findOrFail($id),
            default => abort(404, 'Invalid patient type.'),
        };

        $visits = $patient->clinicVisits()
            ->with(['nurse:id,name', 'medicine:id,medicine_name,unit'])
            ->orderByDesc('visit_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ClinicVisit $v) => [
                'id' => $v->id,
                'visit_date' => $v->visit_date?->format('Y-m-d') ?? (string) $v->visit_date,
                'created_at' => $v->created_at?->toIso8601String(),
                'reason' => $v->reason ?: 'N/A',
                'symptoms' => $v->symptoms ?: 'N/A',
                'temperature' => $v->temperature ?: 'N/A',
                'blood_pressure' => $v->blood_pressure ?: 'N/A',
                'assessment' => $v->assessment ?: 'N/A',
                'treatment' => $v->treatment ?: 'N/A',
                'medicine_name' => $v->medicine?->medicine_name,
                'medicine_quantity' => $v->medicine_quantity,
                'medicine_unit' => $v->medicine?->unit,
                'nurse_name' => $v->nurse?->name ?: 'N/A',
                'remarks' => $v->remarks ?: 'N/A',
                'follow_up_date' => $v->follow_up_date?->format('Y-m-d'),
                'follow_up_status' => $v->follow_up_status,
                'follow_up_notes' => $v->follow_up_notes,
            ]);

        return response()->json([
            'patient_type' => $type,
            'patient_id' => $patient->id,
            'qr_token' => self::makeQrToken($type, $patient->id),
            'total_visits' => $visits->count(),
            'timeline' => $visits,
        ]);
    }

    /**
     * C. Medicine Expiration Intelligence (Expired, <=30d, <=60d, <=90d, FEFO priority).
     */
    public function expiryIntelligence(): JsonResponse
    {
        $today = Carbon::today()->toDateString();
        $d30 = Carbon::today()->addDays(30)->toDateString();
        $d60 = Carbon::today()->addDays(60)->toDateString();
        $d90 = Carbon::today()->addDays(90)->toDateString();

        $batches = MedicineBatch::query()
            ->with('medicine:id,medicine_name,unit,minimum_stock')
            ->where('current_quantity', '>', 0)
            ->whereNotNull('expiration_date')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('date_added', 'asc')
            ->get()
            ->map(function (MedicineBatch $b) use ($today, $d30, $d60, $d90) {
                $exp = Carbon::parse($b->expiration_date)->toDateString();
                $bucket = 'safe';
                if ($exp < $today) {
                    $bucket = 'expired';
                } elseif ($exp <= $d30) {
                    $bucket = 'within_30_days';
                } elseif ($exp <= $d60) {
                    $bucket = 'within_60_days';
                } elseif ($exp <= $d90) {
                    $bucket = 'within_90_days';
                }

                return [
                    'batch_id' => $b->id,
                    'medicine_id' => $b->medicine_id,
                    'medicine_name' => $b->medicine?->medicine_name ?? 'Unknown',
                    'unit' => $b->medicine?->unit ?? 'unit',
                    'batch_number' => $b->batch_number,
                    'current_quantity' => (int) $b->current_quantity,
                    'expiration_date' => $exp,
                    'days_remaining' => Carbon::today()->diffInDays(Carbon::parse($exp), false),
                    'risk_bucket' => $bucket,
                ];
            });

        return response()->json([
            'summary' => [
                'expired_batches' => $batches->where('risk_bucket', 'expired')->count(),
                'expired_units' => $batches->where('risk_bucket', 'expired')->sum('current_quantity'),
                'expiring_30d_batches' => $batches->where('risk_bucket', 'within_30_days')->count(),
                'expiring_30d_units' => $batches->where('risk_bucket', 'within_30_days')->sum('current_quantity'),
                'expiring_60d_batches' => $batches->where('risk_bucket', 'within_60_days')->count(),
                'expiring_90d_batches' => $batches->where('risk_bucket', 'within_90_days')->count(),
            ],
            'batches' => $batches->filter(fn ($b) => $b['risk_bucket'] !== 'safe')->values(),
        ]);
    }

    /**
     * I. Medicine Consumption Forecasting (30 / 60 / 90 days based on actual dispense transactions).
     */
    public function medicineForecast(Request $request): JsonResponse
    {
        $days = in_array((int) $request->query('days', 30), [30, 60, 90], true)
            ? (int) $request->query('days', 30)
            : 30;

        $since = Carbon::today()->subDays($days)->startOfDay();

        $txStats = MedicineTransaction::query()
            ->where('transaction_type', 'dispense')
            ->where('created_at', '>=', $since)
            ->selectRaw('medicine_id, SUM(quantity) as total_dispensed, COUNT(*) as tx_count')
            ->groupBy('medicine_id')
            ->get()
            ->keyBy('medicine_id');

        $medicines = Medicine::query()
            ->select(['id', 'medicine_name', 'unit', 'stock', 'minimum_stock'])
            ->orderBy('medicine_name')
            ->get()
            ->map(function (Medicine $med) use ($txStats, $days) {
                $stat = $txStats->get($med->id);
                $totalDispensed = (int) ($stat->total_dispensed ?? 0);
                $txCount = (int) ($stat->tx_count ?? 0);
                $insufficientData = $txCount < 2 || $totalDispensed <= 0;

                $avgDaily = $insufficientData ? null : round($totalDispensed / $days, 2);
                $estDaysLeft = ($avgDaily && $avgDaily > 0)
                    ? (int) floor(max(0, (int) $med->stock) / $avgDaily)
                    : null;
                $depletionDate = $estDaysLeft !== null
                    ? Carbon::today()->addDays($estDaysLeft)->toDateString()
                    : null;
                $target30dStock = $avgDaily !== null
                    ? (int) ceil($avgDaily * 30)
                    : (int) (($med->minimum_stock ?: 10) * 2);
                $suggestedReorder = max(0, $target30dStock - max(0, (int) $med->stock));

                return [
                    'medicine_id' => $med->id,
                    'medicine_name' => $med->medicine_name,
                    'unit' => $med->unit,
                    'current_stock' => (int) $med->stock,
                    'minimum_stock' => (int) ($med->minimum_stock ?: 10),
                    'dispensed_in_window' => $totalDispensed,
                    'transaction_count' => $txCount,
                    'insufficient_data' => $insufficientData,
                    'avg_daily_consumption' => $avgDaily,
                    'estimated_days_remaining' => $estDaysLeft,
                    'estimated_depletion_date' => $depletionDate,
                    'suggested_reorder_qty' => $suggestedReorder,
                ];
            });

        return response()->json([
            'window_days' => $days,
            'items' => $medicines->values(),
        ]);
    }

    /**
     * D. Follow-up Monitoring — list upcoming, overdue, and completed follow-ups.
     */
    public function followUps(Request $request): JsonResponse
    {
        $today = Carbon::today()->toDateString();
        $statusFilter = $request->query('status', 'all');

        $visits = ClinicVisit::query()
            ->with([
                'student:id,student_id,first_name,last_name,course',
                'faculty:id,employee_id,first_name,last_name,department',
                'staff:id,staff_id,first_name,last_name,department',
                'nurse:id,name',
            ])
            ->whereNotNull('follow_up_date')
            ->orderBy('follow_up_date', 'asc')
            ->limit(100)
            ->get()
            ->map(function (ClinicVisit $v) use ($today) {
                $fDate = $v->follow_up_date?->format('Y-m-d');
                $rawStatus = $v->follow_up_status ?: 'pending';
                $effectiveStatus = ($rawStatus === 'pending' && $fDate && $fDate < $today)
                    ? 'overdue'
                    : $rawStatus;

                $patient = $v->student ?? $v->faculty ?? $v->staff;
                $patientName = $patient
                    ? trim("{$patient->first_name} {$patient->last_name}")
                    : 'Unknown Patient';

                return [
                    'visit_id' => $v->id,
                    'patient_name' => $patientName,
                    'patient_type' => $v->student_id ? 'student' : ($v->faculty_id ? 'faculty' : 'staff'),
                    'visit_date' => $v->visit_date?->format('Y-m-d'),
                    'reason' => $v->reason,
                    'follow_up_date' => $fDate,
                    'follow_up_status' => $effectiveStatus,
                    'follow_up_notes' => $v->follow_up_notes,
                    'nurse_name' => $v->nurse?->name ?? 'N/A',
                ];
            });

        if ($statusFilter !== 'all') {
            $visits = $visits->where('follow_up_status', $statusFilter)->values();
        }

        return response()->json([
            'summary' => [
                'overdue' => $visits->where('follow_up_status', 'overdue')->count(),
                'pending' => $visits->where('follow_up_status', 'pending')->count(),
                'completed' => $visits->where('follow_up_status', 'completed')->count(),
            ],
            'data' => $visits->values(),
        ]);
    }

    public function updateFollowUp(Request $request, int $id): JsonResponse
    {
        $visit = ClinicVisit::findOrFail($id);

        $validated = $request->validate([
            'follow_up_date' => ['nullable', 'date'],
            'follow_up_status' => ['required', 'in:pending,completed,missed'],
            'follow_up_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $visit->update($validated);

        AuditLogService::record(
            'visit.follow_up_updated',
            "Updated follow-up status to {$validated['follow_up_status']} for clinic visit #{$visit->id}",
            'clinic_visit',
            $visit->id,
            [],
            $request->user(),
            $request
        );

        return response()->json([
            'message' => 'Follow-up updated successfully.',
            'clinic_visit' => $visit->fresh(),
        ]);
    }

    /**
     * G & J. Statistical Health Trend Detection & Smart Reporting Executive Summary.
     */
    public function healthTrends(): JsonResponse
    {
        $recentStart = Carbon::today()->subDays(6)->toDateString();
        $today = Carbon::today()->toDateString();
        $baselineStart = Carbon::today()->subDays(34)->toDateString();
        $baselineEnd = Carbon::today()->subDays(7)->toDateString();

        $recentCounts = ClinicVisit::query()
            ->whereBetween('visit_date', [$recentStart, $today])
            ->selectRaw("COALESCE(NULLIF(TRIM(reason), ''), 'Other') as reason_label, COUNT(*) as cnt")
            ->groupBy('reason_label')
            ->pluck('cnt', 'reason_label');

        $baselineCounts = ClinicVisit::query()
            ->whereBetween('visit_date', [$baselineStart, $baselineEnd])
            ->selectRaw("COALESCE(NULLIF(TRIM(reason), ''), 'Other') as reason_label, COUNT(*) as cnt")
            ->groupBy('reason_label')
            ->pluck('cnt', 'reason_label');

        $alerts = [];
        foreach ($recentCounts as $reason => $recentCount) {
            $baselineWeeklyAvg = round(((int) ($baselineCounts[$reason] ?? 0)) / 4, 2);
            if ($recentCount >= 3 && ($baselineWeeklyAvg == 0.0 || $recentCount >= ($baselineWeeklyAvg * 2))) {
                $alerts[] = [
                    'reason' => $reason,
                    'recent_7d_count' => (int) $recentCount,
                    'baseline_weekly_avg' => $baselineWeeklyAvg,
                    'increase_factor' => $baselineWeeklyAvg > 0 ? round($recentCount / $baselineWeeklyAvg, 2) : null,
                    'notice' => "Elevated recorded visits for '{$reason}' ({$recentCount} in last 7 days vs {$baselineWeeklyAvg}/wk baseline).",
                ];
            }
        }

        $total7d = $recentCounts->sum();
        $topReason = $recentCounts->sortDesc()->keys()->first();

        return response()->json([
            'disclaimer' => 'Informational statistical trend monitoring only. Does not constitute a medical diagnosis or confirmed outbreak.',
            'period' => [
                'recent_start' => $recentStart,
                'recent_end' => $today,
                'baseline_start' => $baselineStart,
                'baseline_end' => $baselineEnd,
            ],
            'executive_summary' => $total7d > 0
                ? "Recorded {$total7d} clinic visit(s) over the past 7 days. Most frequent recorded reason: {$topReason}."
                : 'No clinic visits recorded over the past 7 days.',
            'alerts' => $alerts,
        ]);
    }

    /**
     * E. Immutable Audit Logs (Admin only).
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $query = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.trim((string) $request->query('action')).'%');
        }

        return response()->json(
            $query->paginate(max(1, min(100, (int) $request->query('per_page', 25))))
        );
    }
}
