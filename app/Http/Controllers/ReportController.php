<?php

namespace App\Http\Controllers;

use App\Models\ClinicVisit;
use App\Models\Medicine;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function monthly(Request $request)
    {
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $month = (int) $startDate->month;
            $year = (int) $startDate->year;
            $periodLabel = $startDate->format('M d, Y') . ' - ' . $endDate->format('M d, Y');
        } else {
            $request->validate([
                'month' => 'nullable|integer|min:1|max:12',
                'year' => 'nullable|integer|min:2020|max:2100',
            ]);
            $month = (int) ($request->month ?: Carbon::now()->month);
            $year = (int) ($request->year ?: Carbon::now()->year);
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            $periodLabel = $startDate->format('F Y');
        }

        $query = ClinicVisit::query()
            ->select([
                'id', 'student_id', 'faculty_id', 'staff_id', 'nurse_id', 'department',
                'medicine_id', 'medicine_quantity', 'visit_date', 'reason', 'symptoms',
                'temperature', 'blood_pressure',
            ])
            ->with([
                'student:id,student_id,first_name,middle_name,last_name,course,year_level,section',
                'faculty:id,employee_id,first_name,middle_name,last_name,department,position',
                'staff:id,staff_id,first_name,middle_name,last_name,department,position',
                'nurse:id,name',
                'medicine:id,medicine_name,unit',
            ])
            ->whereBetween('visit_date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ]);

        if ($request->filled('patient_type') && $request->patient_type !== 'all') {
            if ($request->patient_type === 'student') {
                $query->whereNotNull('student_id');
            } elseif ($request->patient_type === 'faculty') {
                $query->whereNotNull('faculty_id');
            } elseif ($request->patient_type === 'staff') {
                $query->whereNotNull('staff_id');
            }
        }

        $visits = $query->orderBy('visit_date')
            ->orderBy('id')
            ->get();

        $totalVisits = $visits->count();

        $studentVisits = $visits
            ->whereNotNull('student_id')
            ->count();

        $studentsTreated = $visits
            ->whereNotNull('student_id')
            ->pluck('student_id')
            ->unique()
            ->count();

        $facultyVisits = $visits
            ->whereNotNull('faculty_id')
            ->count();

        $staffVisits = $visits
            ->whereNotNull('staff_id')
            ->count();

        $reasons = $visits
            ->groupBy(function ($visit) {
                $r = trim($visit->reason ?: 'Other');
                if (strtolower($r) === 'other' && ! empty(trim($visit->symptoms ?? ''))) {
                    return trim($visit->symptoms);
                }
                return $r;
            })
            ->map(function ($group, $reason) {
                return [
                    'reason' => $reason,
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();

        $commonIllnesses = $visits
            ->filter(fn ($v) => ! empty(trim($v->symptoms ?? '')))
            ->flatMap(function ($v) {
                return preg_split('/[,;\/]+/', (string) $v->symptoms);
            })
            ->map(fn ($s) => trim((string) $s))
            ->filter(fn ($s) => $s !== '' && strlen($s) > 2)
            ->groupBy(fn ($s) => ucwords(strtolower($s)))
            ->map(fn ($group, $illness) => [
                'illness' => $illness,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(10);

        $medicineUsage = $visits
            ->filter(function ($visit) {
                return $visit->medicine_id
                    && (int) $visit->medicine_quantity > 0;
            })
            ->groupBy('medicine_id')
            ->map(function ($group) {

                $medicine = $group->first()->medicine;

                return [
                    'medicine_id' => $medicine?->id,
                    'medicine_name' => $medicine?->medicine_name ?? 'Unknown',
                    'unit' => $medicine?->unit ?? '',
                    'quantity_used' => $group->sum(
                        fn ($visit) => (int) $visit->medicine_quantity
                    ),
                ];
            })
            ->sortByDesc('quantity_used')
            ->values();

        $dailyVisits = $visits
            ->filter(fn ($visit) => ! empty($visit->visit_date))
            ->groupBy(function ($visit) {
                return Carbon::parse($visit->visit_date)
                    ->format('Y-m-d');
            })
            ->map(function ($group, $date) {

                return [
                    'date' => $date,
                    'count' => $group->count(),
                ];
            })
            ->values();

        $nurseActivity = $visits
            ->filter(fn ($visit) => $visit->nurse)
            ->groupBy('nurse_id')
            ->map(function ($group) {

                $nurse = $group->first()->nurse;

                return [
                    'nurse_id' => $nurse?->id,
                    'name' => $nurse?->name ?? 'Unknown',
                    'visits' => $group->count(),
                ];
            })
            ->sortByDesc('visits')
            ->values();

        $visitDetails = $visits->map(function ($visit) {

            $person = 'Unknown';
            $personType = 'Unknown';

            if ($visit->student) {

                $student = $visit->student;

                $person = collect([
                    $student->first_name ?? null,
                    $student->middle_name ?? null,
                    $student->last_name ?? null,
                ])
                    ->filter()
                    ->implode(' ');

                $person = $person ?: 'Student';

                $personType = 'Student';
            } elseif ($visit->faculty) {

                $faculty = $visit->faculty;

                $person = $this->getPersonName(
                    $faculty,
                    'Faculty'
                );

                $personType = 'Faculty';
            } elseif ($visit->staff) {

                $staff = $visit->staff;

                $person = $this->getPersonName(
                    $staff,
                    'Staff'
                );

                $personType = 'Staff';
            }

            $displayReason = $visit->reason;
            if (strtolower(trim($displayReason ?? '')) === 'other' && ! empty(trim($visit->symptoms ?? ''))) {
                $displayReason = trim($visit->symptoms);
            }

            $dept = $visit->department;
            if (! $dept) {
                if ($visit->faculty && ! empty($visit->faculty->department)) {
                    $dept = $visit->faculty->department;
                } elseif ($visit->staff && ! empty($visit->staff->department)) {
                    $dept = $visit->staff->department;
                } elseif ($visit->student && ! empty($visit->student->course)) {
                    $dept = $this->mapCourseToDepartment($visit->student->course);
                }
            }

            return [
                'id' => $visit->id,

                'date' => $visit->visit_date instanceof Carbon
                    ? $visit->visit_date->format('Y-m-d')
                    : (string) ($visit->visit_date ? substr((string) $visit->visit_date, 0, 10) : ''),

                'person' => $person,

                'person_type' => $personType,

                'department' => $dept ?: 'N/A',

                'reason' => $displayReason,

                'symptoms' => $visit->symptoms,

                'temperature' => $visit->temperature,

                'blood_pressure' => $visit->blood_pressure,

                'medicine' => $visit->medicine?->medicine_name,

                'medicine_quantity' => $visit->medicine_quantity,

                'nurse' => $visit->nurse?->name,
            ];
        })
            ->values();

        return response()->json([

            'report' => [
                'month' => $month,
                'year' => $year,
                'month_name' => $startDate->format('F'),
                'period' => $periodLabel,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'generated_at' => now()->toIso8601String(),
            ],

            'summary' => [
                'total_visits' => $totalVisits,
                'student_visits' => $studentVisits,
                'students_treated' => $studentsTreated,
                'faculty_visits' => $facultyVisits,
                'staff_visits' => $staffVisits,
            ],

            'reasons' => $reasons,

            'common_illnesses' => $commonIllnesses,

            'medicine_usage' => $medicineUsage,

            'daily_visits' => $dailyVisits,

            'nurse_activity' => $nurseActivity,

            'visits' => $visitDetails,
        ]);
    }

    public function medicineReport(Request $request)
    {
        $today = Carbon::today()->toDateString();
        $in30Days = Carbon::today()->addDays(30)->toDateString();

        $medicines = Medicine::query()
            ->orderBy('medicine_name')
            ->get();

        $totalItems = $medicines->count();
        $totalUnitsInStock = $medicines->sum('stock');
        $outOfStock = $medicines->filter(fn($m) => $m->stock <= 0)->count();
        $lowStock = $medicines->filter(fn($m) => $m->stock > 0 && $m->stock <= ($m->minimum_stock ?: 10))->count();
        $expired = $medicines->filter(fn($m) => !empty($m->expiration_date) && $m->expiration_date < $today)->count();
        $expiringSoon = $medicines->filter(fn($m) => !empty($m->expiration_date) && $m->expiration_date >= $today && $m->expiration_date <= $in30Days)->count();

        // Total dispensed from clinic visits
        $dispensedByMedicine = ClinicVisit::query()
            ->whereNotNull('medicine_id')
            ->selectRaw('medicine_id, SUM(medicine_quantity) as total_dispensed, COUNT(*) as visit_count')
            ->groupBy('medicine_id')
            ->pluck('total_dispensed', 'medicine_id');

        $items = $medicines->map(function ($med) use ($today, $in30Days, $dispensedByMedicine) {
            $dispensed = (int) ($dispensedByMedicine[$med->id] ?? 0);
            $isExpired = !empty($med->expiration_date) && $med->expiration_date < $today;
            $isExpiring = !empty($med->expiration_date) && $med->expiration_date >= $today && $med->expiration_date <= $in30Days;
            $isOutOfStock = $med->stock <= 0;
            $isLowStock = !$isOutOfStock && $med->stock <= ($med->minimum_stock ?: 10);

            $status = $isExpired ? 'Expired' : ($isOutOfStock ? 'Out of Stock' : ($isLowStock ? 'Low Stock' : ($isExpiring ? 'Expiring Soon' : 'Active / Good')));

            return [
                'id' => $med->id,
                'medicine_name' => $med->medicine_name,
                'treatment_type' => $med->treatment_type ?: 'General',
                'unit' => $med->unit ?: 'Unit',
                'stock' => (int) $med->stock,
                'minimum_stock' => (int) ($med->minimum_stock ?: 10),
                'expiration_date' => $med->expiration_date,
                'description' => $med->description,
                'total_dispensed' => $dispensed,
                'status' => $status,
                'is_expired' => $isExpired,
                'is_low_stock' => $isLowStock,
                'is_out_of_stock' => $isOutOfStock,
                'is_expiring_soon' => $isExpiring,
            ];
        });

        return response()->json([
            'summary' => [
                'total_items' => $totalItems,
                'total_units_in_stock' => $totalUnitsInStock,
                'out_of_stock' => $outOfStock,
                'low_stock' => $lowStock,
                'expired' => $expired,
                'expiring_soon' => $expiringSoon,
            ],
            'medicines' => $items,
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    private function getPersonName($person, string $fallback): string
    {
        if (! $person) {
            return $fallback;
        }

        if (! empty($person->name)) {
            return $person->name;
        }

        $name = collect([
            $person->first_name ?? null,
            $person->middle_name ?? null,
            $person->last_name ?? null,
        ])
            ->filter()
            ->implode(' ');

        return $name ?: $fallback;
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
}
