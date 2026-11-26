<?php

namespace App\Http\Controllers;

use App\Models\ClinicVisit;
use App\Models\Faculty;
use App\Models\Medicine;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        if (app()->environment('testing')) {
            return response()->json($this->buildDashboardData());
        }

        $data = Cache::remember('clinic_dashboard_data', 15, function () {
            return $this->buildDashboardData();
        });

        return response()->json($data);
    }

    protected function buildDashboardData(): array
    {
        $totalStudents = Student::count();
        $totalStaff = Staff::count();
        $totalFaculty = Faculty::count();
        $totalVisits = ClinicVisit::count();
        $studentVisitsTotal = ClinicVisit::whereNotNull('student_id')->count();
        $employeeVisitsTotal = ClinicVisit::where(function ($q) {
            $q->whereNotNull('faculty_id')->orWhereNotNull('staff_id');
        })->count();

        $studentMale = Student::whereRaw('LOWER(sex) = ?', ['male'])->count();
        $studentFemale = Student::whereRaw('LOWER(sex) = ?', ['female'])->count();

        $staffMale = Staff::whereRaw('LOWER(sex) = ?', ['male'])->count();
        $staffFemale = Staff::whereRaw('LOWER(sex) = ?', ['female'])->count();

        $facultyMale = Faculty::whereRaw('LOWER(sex) = ?', ['male'])->count();
        $facultyFemale = Faculty::whereRaw('LOWER(sex) = ?', ['female'])->count();

        $employeeMale = $staffMale + $facultyMale;
        $employeeFemale = $staffFemale + $facultyFemale;

        $maleCount = $studentMale + $employeeMale;
        $femaleCount = $studentFemale + $employeeFemale;

        $monthStart = now()->subMonths(7)->startOfMonth()->toDateString();
        $isSqlite = DB::getDriverName() === 'sqlite';
        $monthExpr = $isSqlite ? "strftime('%Y-%m', visit_date)" : "DATE_FORMAT(visit_date, '%Y-%m')";

        $monthlyVisits = ClinicVisit::query()
            ->where('visit_date', '>=', $monthStart)
            ->selectRaw("{$monthExpr} as month, COUNT(*) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $dailyStart = now()->subDays(90)->toDateString();
        $dateExpr = $isSqlite ? "strftime('%Y-%m-%d', visit_date)" : "DATE_FORMAT(visit_date, '%Y-%m-%d')";
        $visitTrends = ClinicVisit::query()
            ->where('visit_date', '>=', $dailyStart)
            ->selectRaw("{$dateExpr} as date,
                COUNT(*) as total,
                SUM(CASE WHEN student_id IS NOT NULL THEN 1 ELSE 0 END) as students,
                SUM(CASE WHEN faculty_id IS NOT NULL OR staff_id IS NOT NULL THEN 1 ELSE 0 END) as employees")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $visitReasons = ClinicVisit::query()
            ->select(['id', 'reason', 'symptoms'])
            ->get()
            ->groupBy(function ($v) {
                $reason = trim((string) ($v->reason ?? ''));
                $symptoms = trim((string) ($v->symptoms ?? ''));
                if (strtolower($reason) === 'other' && $symptoms !== '') {
                    return $symptoms;
                }

                return $reason !== '' ? $reason : 'Other';
            })
            ->map(fn ($group, $name) => [
                'label' => $name,
                'value' => $group->count(),
            ])
            ->sortByDesc('value')
            ->values()
            ->take(6);

        $recentStudents = Student::query()
            ->select([
                'id',
                'student_id',
                'first_name',
                'middle_name',
                'last_name',
                'course',
                'year_level',
                'sex',
                'created_at',
            ])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($s) => array_merge($s->toArray(), [
                'patient_type' => 'student',
                'id_number' => $s->student_id,
            ]));

        $recentStaff = Staff::query()
            ->select([
                'id',
                'staff_id',
                'first_name',
                'middle_name',
                'last_name',
                'position',
                'department',
                'sex',
                'created_at',
            ])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($st) => array_merge($st->toArray(), [
                'patient_type' => 'staff',
                'id_number' => $st->staff_id,
            ]));

        $recentFaculty = Faculty::query()
            ->select([
                'id',
                'employee_id',
                'first_name',
                'middle_name',
                'last_name',
                'position',
                'department',
                'sex',
                'created_at',
            ])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($f) => array_merge($f->toArray(), [
                'patient_type' => 'faculty',
                'id_number' => $f->employee_id,
            ]));

        $recentPatients = $recentStudents
            ->concat($recentStaff)
            ->concat($recentFaculty)
            ->sortByDesc('created_at')
            ->values()
            ->take(5);

        $today = now()->toDateString();
        // Only alert/notify for medicines within 3 days before expiration
        $soonDate = now()->addDays(3)->toDateString();

        $expiredMedicines = Medicine::query()
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<', $today)
            ->select(['id', 'medicine_name', 'expiration_date', 'stock', 'unit'])
            ->orderBy('expiration_date')
            ->limit(10)
            ->get();

        $expiringSoonMedicines = Medicine::query()
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '>=', $today)
            ->where('expiration_date', '<=', $soonDate)
            ->select(['id', 'medicine_name', 'expiration_date', 'stock', 'unit'])
            ->orderBy('expiration_date')
            ->limit(10)
            ->get();

        return [
            'total_students' => $totalStudents,
            'total_patients' => $totalStudents + $totalStaff + $totalFaculty,
            'total_visits' => $totalVisits,
            'total_records' => $totalVisits,
            'visit_totals' => [
                'total' => $totalVisits,
                'students' => $studentVisitsTotal,
                'employees' => $employeeVisitsTotal,
            ],
            'medicine_alerts' => [
                'expired_count' => Medicine::whereNotNull('expiration_date')->where('expiration_date', '<', $today)->count(),
                'expiring_soon_count' => Medicine::whereNotNull('expiration_date')->where('expiration_date', '>=', $today)->where('expiration_date', '<=', $soonDate)->count(),
                'expired' => $expiredMedicines,
                'expiring_soon' => $expiringSoonMedicines,
            ],
            'student_gender_counts' => [
                'male' => $studentMale,
                'female' => $studentFemale,
            ],
            'faculty_staff_gender_counts' => [
                'male' => $employeeMale,
                'female' => $employeeFemale,
                'staff' => ['male' => $staffMale, 'female' => $staffFemale],
                'faculty' => ['male' => $facultyMale, 'female' => $facultyFemale],
            ],
            'employee_gender_counts' => [
                'male' => $employeeMale,
                'female' => $employeeFemale,
            ],
            'patient_gender_counts' => [
                'male' => $maleCount,
                'female' => $femaleCount,
                'students' => [
                    'male' => $studentMale,
                    'female' => $studentFemale,
                ],
                'faculty_staff' => [
                    'male' => $employeeMale,
                    'female' => $employeeFemale,
                ],
                'employees' => [
                    'male' => $employeeMale,
                    'female' => $employeeFemale,
                ],
            ],
            'monthly_visits' => $monthlyVisits,
            'visit_trends' => $visitTrends,
            'visit_reasons' => $visitReasons,
            'recent_students' => $recentPatients,
            'recent_patients' => $recentPatients,
            'recent_visits' => ClinicVisit::query()
                ->select([
                    'id',
                    'student_id',
                    'faculty_id',
                    'staff_id',
                    'visit_date',
                    'reason',
                    'created_at',
                ])
                ->with([
                    'student:id,student_id,first_name,middle_name,last_name',
                    'faculty:id,employee_id,first_name,middle_name,last_name',
                    'staff:id,staff_id,first_name,middle_name,last_name',
                ])
                ->latest('visit_date')
                ->latest('id')
                ->limit(5)
                ->get(),
        ];
    }
}
