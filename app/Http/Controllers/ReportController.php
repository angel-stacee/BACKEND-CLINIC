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

        $visitsByDepartment = $visits->groupBy(function ($visit) {
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
            return $dept ?: 'N/A';
        })->map(function ($group, $dept) use ($totalVisits) {
            return [
                'department' => $dept,
                'short_name' => $this->getDepartmentShortName($dept),
                'visits' => $group->count(),
                'percentage' => $totalVisits > 0 ? round(($group->count() / $totalVisits) * 100, 1) : 0,
            ];
        })->sortByDesc('visits')->values();

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

            'visits_by_department' => $visitsByDepartment,

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

    public function analytics(Request $request)
    {
        $periodType = $request->input('period_type', 'monthly');
        $now = Carbon::now();
        $month = null;
        $year = null;

        switch ($periodType) {
            case 'daily':
                $refDate = $request->filled('date') ? Carbon::parse($request->date) : $now->copy();
                $startDate = $refDate->copy()->startOfDay();
                $endDate = $refDate->copy()->endOfDay();
                $periodLabel = 'Daily (' . $startDate->format('M d, Y') . ')';
                break;

            case 'weekly':
                $refDate = $request->filled('date') ? Carbon::parse($request->date) : $now->copy();
                $startDate = $refDate->copy()->startOfWeek();
                $endDate = $refDate->copy()->endOfWeek();
                $periodLabel = 'Weekly (' . $startDate->format('M d') . ' - ' . $endDate->format('M d, Y') . ')';
                break;

            case 'monthly':
                $month = (int) ($request->month ?: $now->month);
                $year = (int) ($request->year ?: $now->year);
                $startDate = Carbon::create($year, $month, 1)->startOfMonth();
                $endDate = $startDate->copy()->endOfMonth();
                $periodLabel = 'Monthly (' . $startDate->format('F Y') . ')';
                break;

            case 'yearly':
                $year = (int) ($request->year ?: $now->year);
                $startDate = Carbon::create($year, 1, 1)->startOfYear();
                $endDate = Carbon::create($year, 12, 31)->endOfYear();
                $periodLabel = 'Yearly (' . $year . ')';
                break;

            case 'custom':
                $request->validate([
                    'start_date' => 'required|date',
                    'end_date' => 'required|date|after_or_equal:start_date',
                ]);
                $startDate = Carbon::parse($request->start_date)->startOfDay();
                $endDate = Carbon::parse($request->end_date)->endOfDay();
                $periodLabel = 'Custom (' . $startDate->format('M d, Y') . ' - ' . $endDate->format('M d, Y') . ')';
                break;

            case 'all':
            default:
                $periodType = 'all';
                $startDate = Carbon::create(2020, 1, 1)->startOfDay();
                $endDate = Carbon::create(2100, 12, 31)->endOfDay();
                $periodLabel = 'All Recorded Visits';
                break;
        }

        $query = ClinicVisit::query()
            ->select([
                'id', 'student_id', 'faculty_id', 'staff_id', 'nurse_id', 'department',
                'medicine_id', 'medicine_quantity', 'visit_date', 'reason', 'symptoms',
                'temperature', 'blood_pressure', 'created_at',
            ])
            ->with([
                'student:id,student_id,first_name,middle_name,last_name,course,year_level,section',
                'faculty:id,employee_id,first_name,middle_name,last_name,department,position',
                'staff:id,staff_id,first_name,middle_name,last_name,department,position',
            ]);

        if ($periodType !== 'all') {
            $query->whereBetween('visit_date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ]);
        }

        if ($request->filled('patient_type') && $request->patient_type !== 'all') {
            if ($request->patient_type === 'student') {
                $query->whereNotNull('student_id');
            } elseif ($request->patient_type === 'faculty') {
                $query->whereNotNull('faculty_id');
            } elseif ($request->patient_type === 'staff') {
                $query->whereNotNull('staff_id');
            }
        }

        $visits = $query->orderBy('visit_date', 'asc')->get();

        $totalVisits = $visits->count();
        $studentVisits = $visits->whereNotNull('student_id')->count();
        $facultyVisits = $visits->whereNotNull('faculty_id')->count();
        $staffVisits = $visits->whereNotNull('staff_id')->count();

        // Department map: deptName => ['visits' => int, 'symptoms' => [symptom => count]]
        $deptStats = [];
        // Overall symptoms map: symptom => count
        $overallSymptoms = [];

        foreach ($visits as $visit) {
            // 1. Resolve Department
            $dept = trim($visit->department ?? '');
            if (empty($dept)) {
                if ($visit->faculty && !empty($visit->faculty->department)) {
                    $dept = trim($visit->faculty->department);
                } elseif ($visit->staff && !empty($visit->staff->department)) {
                    $dept = trim($visit->staff->department);
                } elseif ($visit->student && !empty($visit->student->course)) {
                    $dept = $this->mapCourseToDepartment($visit->student->course);
                }
            }
            $dept = !empty($dept) ? $dept : 'N/A';

            if (!isset($deptStats[$dept])) {
                $deptStats[$dept] = [
                    'department' => $dept,
                    'short_name' => $this->getDepartmentShortName($dept),
                    'visits' => 0,
                    'symptoms' => [],
                ];
            }
            $deptStats[$dept]['visits']++;

            // 2. Extract Symptoms
            $visitSymptoms = [];
            $rawSymptoms = trim((string)($visit->symptoms ?? ''));
            if (!empty($rawSymptoms)) {
                $parts = preg_split('/[,;\/]+/', $rawSymptoms);
                foreach ($parts as $p) {
                    $clean = trim($p);
                    if (!empty($clean) && strlen($clean) > 2) {
                        $visitSymptoms[] = ucwords(strtolower($clean));
                    }
                }
            }

            if (empty($visitSymptoms) && !empty($visit->reason)) {
                $r = trim($visit->reason);
                $lowR = strtolower($r);
                if (!in_array($lowR, ['other', 'routine checkup', 'medical certificate', 'n/a', 'none', 'consultation'])) {
                    $visitSymptoms[] = ucwords($lowR);
                }
            }

            if (empty($visitSymptoms)) {
                $visitSymptoms[] = 'N/A';
            }
            $visitSymptoms = array_unique($visitSymptoms);

            foreach ($visitSymptoms as $sym) {
                $overallSymptoms[$sym] = ($overallSymptoms[$sym] ?? 0) + 1;
                $deptStats[$dept]['symptoms'][$sym] = ($deptStats[$dept]['symptoms'][$sym] ?? 0) + 1;
            }
        }

        $visitsByDepartment = collect($deptStats)
            ->map(function ($d) use ($totalVisits) {
                return [
                    'department' => $d['department'],
                    'short_name' => $d['short_name'],
                    'visits' => $d['visits'],
                    'percentage' => $totalVisits > 0 ? round(($d['visits'] / $totalVisits) * 100, 1) : 0,
                    'is_highest' => false,
                ];
            })
            ->sortByDesc('visits')
            ->values()
            ->toArray();

        if (!empty($visitsByDepartment) && $visitsByDepartment[0]['visits'] > 0) {
            $visitsByDepartment[0]['is_highest'] = true;
        }

        $topDept = !empty($visitsByDepartment) ? $visitsByDepartment[0] : [
            'department' => 'None',
            'short_name' => 'None',
            'visits' => 0,
            'percentage' => 0,
        ];

        $totalSymptomCounts = array_sum($overallSymptoms);
        $commonSymptoms = collect($overallSymptoms)
            ->map(function ($count, $symptom) use ($totalSymptomCounts) {
                return [
                    'symptom' => $symptom,
                    'count' => $count,
                    'percentage' => $totalSymptomCounts > 0 ? round(($count / $totalSymptomCounts) * 100, 1) : 0,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->map(function ($item, $idx) {
                $item['rank'] = $idx + 1;
                return $item;
            })
            ->toArray();

        // Top symptom candidate (prefer clinical symptom over 'N/A' if exists)
        $topSymptomCandidate = collect($commonSymptoms)->first(fn($s) => $s['symptom'] !== 'N/A') ?? ($commonSymptoms[0] ?? null);
        $topSymptom = $topSymptomCandidate ? [
            'symptom' => $topSymptomCandidate['symptom'],
            'count' => $topSymptomCandidate['count'],
            'percentage' => $topSymptomCandidate['percentage'],
        ] : [
            'symptom' => 'None',
            'count' => 0,
            'percentage' => 0,
        ];

        // Top 5 symptom keys for grouped cross-department chart
        $topSymptomKeys = collect($commonSymptoms)
            ->filter(fn($s) => $s['symptom'] !== 'N/A' || count($commonSymptoms) === 1)
            ->take(5)
            ->pluck('symptom')
            ->toArray();

        $deptSymptomsDetailed = collect($deptStats)
            ->map(function ($d) {
                $sList = collect($d['symptoms'])
                    ->map(fn($cnt, $sym) => ['symptom' => $sym, 'count' => $cnt])
                    ->sortByDesc('count')
                    ->values()
                    ->toArray();

                return [
                    'department' => $d['department'],
                    'short_name' => $d['short_name'],
                    'total_visits' => $d['visits'],
                    'top_symptom' => !empty($sList) ? $sList[0]['symptom'] : 'None',
                    'symptoms' => $sList,
                ];
            })
            ->sortByDesc('total_visits')
            ->values()
            ->toArray();

        $groupedChartData = collect($deptStats)
            ->map(function ($d) use ($topSymptomKeys) {
                $row = [
                    'department' => $d['department'],
                    'short_name' => $d['short_name'],
                    'total_visits' => $d['visits'],
                ];
                foreach ($topSymptomKeys as $symKey) {
                    $row[$symKey] = $d['symptoms'][$symKey] ?? 0;
                }
                return $row;
            })
            ->sortByDesc('total_visits')
            ->values()
            ->toArray();

        $totalDepartmentsCount = count(array_filter(array_keys($deptStats), fn($d) => $d !== 'N/A' || count($deptStats) === 1));

        return response()->json([
            'summary' => [
                'total_visits' => $totalVisits,
                'top_department' => $topDept,
                'top_symptom' => $topSymptom,
                'total_departments' => $totalDepartmentsCount,
                'patient_type_counts' => [
                    'student_visits' => $studentVisits,
                    'faculty_visits' => $facultyVisits,
                    'staff_visits' => $staffVisits,
                ],
            ],
            'visits_by_department' => $visitsByDepartment,
            'common_symptoms' => $commonSymptoms,
            'department_symptoms' => $deptSymptomsDetailed,
            'grouped_comparison' => [
                'data' => $groupedChartData,
                'symptom_keys' => $topSymptomKeys,
            ],
            'period' => [
                'type' => $periodType,
                'label' => $periodLabel,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'month' => $month ?: $now->month,
                'year' => $year ?: $now->year,
            ],
            'all_departments' => array_values(array_unique(array_column($visitsByDepartment, 'department'))),
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    private function getDepartmentShortName(string $dept): string
    {
        if (preg_match('/\(([^)]+)\)/', $dept, $matches)) {
            return trim($matches[1]);
        }
        $lower = strtolower($dept);
        if (str_contains($lower, 'guidance')) return 'Guidance';
        if (str_contains($lower, 'midwifery') || str_contains($lower, 'nursing')) return 'Midwifery';
        if (str_contains($lower, 'education')) return 'Education';
        if (str_contains($lower, 'business')) return 'Business Admin';
        if (str_contains($lower, 'criminal')) return 'Criminology';
        if (str_contains($lower, 'hospitality')) return 'Hospitality';
        if (strlen($dept) > 18) {
            return substr($dept, 0, 16) . '...';
        }
        return $dept;
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
