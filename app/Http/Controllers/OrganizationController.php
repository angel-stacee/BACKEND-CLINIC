<?php

namespace App\Http\Controllers;

use App\Models\OrganizationCategory;
use App\Models\OrganizationMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    /**
     * Get the full organization data structure for clinic and administration.
     * Public endpoint so landing page and dashboard can display real-time members.
     */
    public function index(): JsonResponse
    {
        $this->ensureSeeded();

        return response()->json($this->buildFullStructure());
    }

    /**
     * Create or update a member in the database.
     */
    public function saveMember(Request $request, ?string $id = null): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'nullable|string|max:50',
            'category' => 'required|string|in:clinic,admin',
            'name' => 'required|string|max:255',
            'role' => 'required|string|max:255',
            'department' => 'required|string|max:255',
            'credentials' => 'nullable|string|max:255',
            'level' => 'nullable|integer|min:1|max:10',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:255',
            'dutyHours' => 'nullable|string|max:100',
            'duty_hours' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:50',
            'avatarBg' => 'nullable|string|max:50',
            'avatar_bg' => 'nullable|string|max:50',
            'badgeColor' => 'nullable|string|max:100',
            'badge_color' => 'nullable|string|max:100',
            'responsibilities' => 'nullable|array',
            'reportsTo' => 'nullable|string|max:50',
            'reports_to' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer',
        ]);

        $category = $validated['category'];
        $memberId = $id ?? $request->input('id');

        if (!$memberId) {
            $prefix = $category === 'clinic' ? 'c' : 'a';
            $memberId = $prefix . '-' . Str::random(6);
        }

        $dutyHours = $request->input('dutyHours', $request->input('duty_hours', ''));
        $avatarBg = $request->input('avatarBg', $request->input('avatar_bg', 'bg-[#70091f]'));
        $badgeColor = $request->input('badgeColor', $request->input('badge_color', ''));
        $reportsTo = $request->input('reportsTo', $request->input('reports_to', null));

        // Avoid self-reporting loop
        if ($reportsTo === $memberId) {
            $reportsTo = null;
        }

        $member = OrganizationMember::updateOrCreate(
            ['id' => $memberId],
            [
                'category' => $category,
                'name' => $validated['name'],
                'role' => $validated['role'],
                'department' => $validated['department'],
                'credentials' => $validated['credentials'] ?? '',
                'level' => (int) ($validated['level'] ?? 1),
                'email' => $validated['email'] ?? '',
                'phone' => $validated['phone'] ?? '',
                'location' => $validated['location'] ?? '',
                'duty_hours' => $dutyHours,
                'status' => $validated['status'] ?? 'Active Duty',
                'avatar_bg' => $avatarBg,
                'badge_color' => $badgeColor,
                'responsibilities' => $validated['responsibilities'] ?? [],
                'reports_to' => $reportsTo,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Personnel saved successfully to the database.',
            'member' => $member->toFrontendArray(),
            'organization' => $this->buildFullStructure(),
        ]);
    }

    /**
     * Delete a member from the database.
     */
    public function deleteMember(string $id): JsonResponse
    {
        $member = OrganizationMember::find($id);

        if (!$member) {
            return response()->json([
                'status' => 'error',
                'message' => 'Personnel not found.',
            ], 404);
        }

        // Clean up any direct reports so they aren't orphaned
        OrganizationMember::where('reports_to', $id)->update(['reports_to' => $member->reports_to]);

        $member->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Personnel removed from the organization database.',
            'organization' => $this->buildFullStructure(),
        ]);
    }

    /**
     * Reset the organization hierarchy and categories to official institution defaults.
     */
    public function resetDefaults(): JsonResponse
    {
        OrganizationMember::truncate();
        OrganizationCategory::truncate();

        $this->ensureSeeded(true);

        return response()->json([
            'status' => 'success',
            'message' => 'Organization hierarchy has been reset to official defaults in the database.',
            'organization' => $this->buildFullStructure(),
        ]);
    }

    /**
     * Update category header title or tagline.
     */
    public function updateCategory(Request $request, string $category): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'tagline' => 'nullable|string',
        ]);

        $cat = OrganizationCategory::updateOrCreate(
            ['category' => $category],
            [
                'title' => $validated['title'],
                'tagline' => $validated['tagline'] ?? '',
            ]
        );

        return response()->json([
            'status' => 'success',
            'category' => $cat,
            'organization' => $this->buildFullStructure(),
        ]);
    }

    /**
     * Build the structured array required by React frontend.
     */
    private function buildFullStructure(): array
    {
        $categories = OrganizationCategory::with('members')->get()->keyBy('category');

        $result = [];
        $defaultCategories = [
            'clinic' => [
                'title' => 'Clinic Healthcare Team',
                'tagline' => 'Medical officers, clinical nurses, midwives, and healthcare specialists serving the TCC campus daily.',
            ],
            'admin' => [
                'title' => 'College Administration & Health Services',
                'tagline' => 'Executive leadership guiding health governance, institutional accreditation, and campus student welfare.',
            ],
        ];

        foreach (['clinic', 'admin'] as $catKey) {
            $categoryModel = $categories->get($catKey);
            $title = $categoryModel?->title ?? $defaultCategories[$catKey]['title'];
            $tagline = $categoryModel?->tagline ?? $defaultCategories[$catKey]['tagline'];

            $members = [];
            if ($categoryModel && $categoryModel->members) {
                foreach ($categoryModel->members as $member) {
                    $members[] = $member->toFrontendArray();
                }
            }

            $result[$catKey] = [
                'title' => $title,
                'tagline' => $tagline,
                'members' => $members,
            ];
        }

        return $result;
    }

    /**
     * Ensure the organization tables have baseline records.
     */
    private function ensureSeeded(bool $force = false): void
    {
        if (!$force && OrganizationMember::exists()) {
            return;
        }

        OrganizationCategory::updateOrCreate(
            ['category' => 'clinic'],
            [
                'title' => 'Clinic Healthcare Team',
                'tagline' => 'Medical officers, clinical nurses, midwives, and healthcare specialists serving the TCC campus daily.',
            ]
        );

        OrganizationCategory::updateOrCreate(
            ['category' => 'admin'],
            [
                'title' => 'College Administration & Health Services',
                'tagline' => 'Executive leadership guiding health governance, institutional accreditation, and campus student welfare.',
            ]
        );

        $clinicMembers = [
            [
                'id' => 'c-1',
                'category' => 'clinic',
                'name' => 'Dr. Maria Elena Ramos, MD',
                'role' => 'College Medical Officer / Head Physician',
                'department' => 'Health Services & Diagnostics',
                'credentials' => 'Doctor of Medicine (MD) · Board Certified',
                'level' => 1,
                'email' => 'dr.ramos@tcc.edu.ph',
                'phone' => '+63 (088) 555-0101',
                'location' => 'Baluarte Campus Clinic · Room 101',
                'duty_hours' => 'Mon - Thu · 8:00 AM - 5:00 PM',
                'status' => 'In Clinic',
                'avatar_bg' => 'bg-[#70091f]',
                'badge_color' => 'bg-[#70091f]/10 text-[#70091f] border-[#70091f]/30 dark:bg-[#70091f]/30 dark:text-[#fcebe7]',
                'responsibilities' => [
                    'Directs campus health policy and patient diagnosis',
                    'Conducts routine student & faculty clinical evaluations',
                    'Supervises prescription dispensations and emergency protocols',
                ],
                'reports_to' => null,
                'sort_order' => 1,
            ],
            [
                'id' => 'c-2',
                'category' => 'clinic',
                'name' => 'Angel Santos, RN, MAN',
                'role' => 'Head Nurse & Clinic Coordinator',
                'department' => 'Nursing & Clinic Operations',
                'credentials' => 'BS Nursing · Master of Arts in Nursing (MAN)',
                'level' => 2,
                'email' => 'angel.santos@tcc.edu.ph',
                'phone' => '+63 (088) 555-0102',
                'location' => 'Baluarte Campus Clinic · Triage Station',
                'duty_hours' => 'Mon - Fri · 7:30 AM - 4:30 PM',
                'status' => 'Active Duty',
                'avatar_bg' => 'bg-[#8b1505]',
                'badge_color' => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800',
                'responsibilities' => [
                    'Coordinates day-to-day clinic visits and patient intake',
                    'Manages student vital signs and immunization tracking',
                    'Supervises junior staff nurses and clinical interns',
                ],
                'reports_to' => 'c-1',
                'sort_order' => 2,
            ],
            [
                'id' => 'c-3',
                'category' => 'clinic',
                'name' => 'Carmela Bautista, RN',
                'role' => 'Staff Nurse (Primary Care & Triage)',
                'department' => 'Primary Healthcare Unit',
                'credentials' => 'BS Nursing · Registered Nurse (RN)',
                'level' => 3,
                'email' => 'carmela.bautista@tcc.edu.ph',
                'phone' => '+63 (088) 555-0103',
                'location' => 'Baluarte Campus Clinic · Bed Ward 1',
                'duty_hours' => 'Mon - Fri · 8:00 AM - 5:00 PM',
                'status' => 'Active Duty',
                'avatar_bg' => 'bg-[#5c0819]',
                'badge_color' => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800',
                'responsibilities' => [
                    'First aid treatment and wound dressing',
                    'Patient vital signs recording and consultation logs',
                    'Assisting physician in student physical examinations',
                ],
                'reports_to' => 'c-2',
                'sort_order' => 3,
            ],
            [
                'id' => 'c-4',
                'category' => 'clinic',
                'name' => 'Jocelyn Dela Cruz, RM, RN',
                'role' => 'Staff Midwife & Maternal Health Specialist',
                'department' => 'Maternal & Reproductive Healthcare',
                'credentials' => 'Registered Midwife (RM) · Registered Nurse (RN)',
                'level' => 3,
                'email' => 'jocelyn.delacruz@tcc.edu.ph',
                'phone' => '+63 (088) 555-0104',
                'location' => 'Baluarte Campus Clinic · Examination Room',
                'duty_hours' => 'Mon - Fri · 8:00 AM - 5:00 PM',
                'status' => 'Active Duty',
                'avatar_bg' => 'bg-[#a31a08]',
                'badge_color' => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-800',
                'responsibilities' => [
                    'Maternal and reproductive health counseling',
                    'Fetal Doppler monitoring and routine vitals assessment',
                    'Women\'s health wellness education seminars',
                ],
                'reports_to' => 'c-2',
                'sort_order' => 4,
            ],
            [
                'id' => 'c-5',
                'category' => 'clinic',
                'name' => 'Mark Anthony Dizon, RN',
                'role' => 'Emergency & First Aid Nurse',
                'department' => 'Emergency Response & Triage',
                'credentials' => 'BS Nursing · BLS / ACLS Certified',
                'level' => 3,
                'email' => 'mark.dizon@tcc.edu.ph',
                'phone' => '+63 (088) 555-0105',
                'location' => 'Baluarte Campus Clinic · Emergency Bay',
                'duty_hours' => 'Mon - Fri · 8:00 AM - 5:00 PM',
                'status' => 'On Call',
                'avatar_bg' => 'bg-[#70091f]',
                'badge_color' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800',
                'responsibilities' => [
                    'Emergency trauma response on campus grounds',
                    'Maintenance of emergency kit, oxygen, and stretchers',
                    'CPR and emergency medical transport coordination',
                ],
                'reports_to' => 'c-2',
                'sort_order' => 5,
            ],
            [
                'id' => 'c-6',
                'category' => 'clinic',
                'name' => 'Kristine Mae Perez, RPh',
                'role' => 'Clinic Pharmacist & Dispensary Custodian',
                'department' => 'Pharmacy & Medicine Inventory',
                'credentials' => 'BS Pharmacy · Registered Pharmacist (RPh)',
                'level' => 4,
                'email' => 'kristine.perez@tcc.edu.ph',
                'phone' => '+63 (088) 555-0106',
                'location' => 'Baluarte Campus Clinic · Pharmacy Room',
                'duty_hours' => 'Mon - Fri · 8:30 AM - 4:30 PM',
                'status' => 'Active Duty',
                'avatar_bg' => 'bg-[#480613]',
                'badge_color' => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800',
                'responsibilities' => [
                    'Medicine stock tracking, expiration audits & re-ordering',
                    'Safe prescription dispensing and dosage instructions',
                    'Inventory logging on the digital clinic management system',
                ],
                'reports_to' => 'c-2',
                'sort_order' => 6,
            ],
            [
                'id' => 'c-7',
                'category' => 'clinic',
                'name' => 'Ryan Paul Villanueva',
                'role' => 'Medical Records Officer & Health Data Custodian',
                'department' => 'Health Records & Statistics',
                'credentials' => 'BS Information Technology · Health Records Specialist',
                'level' => 4,
                'email' => 'ryan.villanueva@tcc.edu.ph',
                'phone' => '+63 (088) 555-0107',
                'location' => 'Baluarte Campus Clinic · Archive Section',
                'duty_hours' => 'Mon - Fri · 8:00 AM - 5:00 PM',
                'status' => 'In Clinic',
                'avatar_bg' => 'bg-[#2e030c]',
                'badge_color' => 'bg-stone-50 text-stone-700 border-stone-200 dark:bg-stone-900 dark:text-stone-300 dark:border-stone-700',
                'responsibilities' => [
                    'Maintenance of student digital and physical health folders',
                    'Processing medical certificates and clearance documents',
                    'Generation of monthly health statistics and clinic analytics',
                ],
                'reports_to' => 'c-2',
                'sort_order' => 7,
            ],
        ];

        foreach ($clinicMembers as $m) {
            OrganizationMember::updateOrCreate(['id' => $m['id']], $m);
        }

        $adminMembers = [
            [
                'id' => 'a-1',
                'category' => 'admin',
                'name' => 'Dr. Lincoln V. Tan, EdD',
                'role' => 'College President',
                'department' => 'Office of the President',
                'credentials' => 'Doctor of Education (EdD) · Institutional Head',
                'level' => 1,
                'email' => 'president@tcc.edu.ph',
                'phone' => '+63 (088) 555-0001',
                'location' => 'TCC Administration Building · Executive Suite',
                'duty_hours' => 'Mon - Fri · 8:00 AM - 5:00 PM',
                'status' => 'Administrative',
                'avatar_bg' => 'bg-[#70091f]',
                'badge_color' => 'bg-[#70091f]/10 text-[#70091f] border-[#70091f]/30 dark:bg-[#70091f]/30 dark:text-[#fcebe7]',
                'responsibilities' => [
                    'Institutional direction and educational governance',
                    'Sanctioning campus health & safety operational budgets',
                    'Liaison with municipal government and healthcare partners',
                ],
                'reports_to' => null,
                'sort_order' => 1,
            ],
            [
                'id' => 'a-2',
                'category' => 'admin',
                'name' => 'Dr. Rebecca S. Reyes, PhD',
                'role' => 'Vice President for Academic Affairs',
                'department' => 'Academic Affairs Directorate',
                'credentials' => 'PhD in Educational Leadership · ALCUCOA Lead Evaluator',
                'level' => 2,
                'email' => 'vpaa@tcc.edu.ph',
                'phone' => '+63 (088) 555-0002',
                'location' => 'TCC Administration Building · 2nd Floor',
                'duty_hours' => 'Mon - Fri · 8:00 AM - 5:00 PM',
                'status' => 'Administrative',
                'avatar_bg' => 'bg-[#8b1505]',
                'badge_color' => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800',
                'responsibilities' => [
                    'Academic integration of campus healthcare programs',
                    'Institutional medical requirements for enrolled students',
                    'Faculty wellness and health development programs',
                ],
                'reports_to' => 'a-1',
                'sort_order' => 2,
            ],
            [
                'id' => 'a-3',
                'category' => 'admin',
                'name' => 'Prof. Rowena G. Alcantara, RN, MN',
                'role' => 'Dean, College of Nursing & Midwifery',
                'department' => 'College of Nursing & Health Sciences',
                'credentials' => 'BS Nursing · Master of Nursing (MN)',
                'level' => 3,
                'email' => 'dean.nursing@tcc.edu.ph',
                'phone' => '+63 (088) 555-0003',
                'location' => 'Health Sciences Building · Dean\'s Office',
                'duty_hours' => 'Mon - Fri · 8:00 AM - 5:00 PM',
                'status' => 'Active Duty',
                'avatar_bg' => 'bg-[#5c0819]',
                'badge_color' => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800',
                'responsibilities' => [
                    'Supervision of nursing & midwifery clinical affiliations',
                    'Clinical curriculum and competency standards',
                    'Collaboration between academic department & campus clinic',
                ],
                'reports_to' => 'a-2',
                'sort_order' => 3,
            ],
            [
                'id' => 'a-4',
                'category' => 'admin',
                'name' => 'Prof. Edgardo M. Morales, MAEd',
                'role' => 'Dean of Student Affairs & Services',
                'department' => 'Student Affairs and Services (SAS)',
                'credentials' => 'MA in Educational Management',
                'level' => 3,
                'email' => 'osas@tcc.edu.ph',
                'phone' => '+63 (088) 555-0004',
                'location' => 'Student Center · Room 204',
                'duty_hours' => 'Mon - Fri · 8:00 AM - 5:00 PM',
                'status' => 'Administrative',
                'avatar_bg' => 'bg-[#480613]',
                'badge_color' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800',
                'responsibilities' => [
                    'Student welfare and emergency crisis assistance',
                    'Campus-wide annual medical and dental checkup drives',
                    'Coordination of medical clearance for collegiate athletes',
                ],
                'reports_to' => 'a-1',
                'sort_order' => 4,
            ],
        ];

        foreach ($adminMembers as $m) {
            OrganizationMember::updateOrCreate(['id' => $m['id']], $m);
        }
    }
}
