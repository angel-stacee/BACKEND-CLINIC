<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Organization categories table (clinic, admin)
        Schema::create('organization_categories', function (Blueprint $table) {
            $table->string('category', 50)->primary();
            $table->string('title');
            $table->text('tagline')->nullable();
            $table->timestamps();
        });

        // 2. Organization members table
        Schema::create('organization_members', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('category', 50)->index();
            $table->string('name');
            $table->string('role');
            $table->string('department');
            $table->string('credentials')->nullable();
            $table->integer('level')->default(1);
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('location')->nullable();
            $table->string('duty_hours')->nullable();
            $table->string('status', 50)->default('Active Duty');
            $table->string('avatar_bg', 50)->default('bg-[#70091f]');
            $table->string('badge_color')->nullable();
            $table->json('responsibilities')->nullable();
            $table->string('reports_to', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('category')->references('category')->on('organization_categories')->onDelete('cascade');
        });

        // Seed default categories
        DB::table('organization_categories')->insert([
            [
                'category' => 'clinic',
                'title' => 'Clinic Healthcare Team',
                'tagline' => 'Medical officers, clinical nurses, midwives, and healthcare specialists serving the TCC campus daily.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'admin',
                'title' => 'College Administration & Health Services',
                'tagline' => 'Executive leadership guiding health governance, institutional accreditation, and campus student welfare.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed default clinic members
        DB::table('organization_members')->insert([
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
                'responsibilities' => json_encode([
                    'Directs campus health policy and patient diagnosis',
                    'Conducts routine student & faculty clinical evaluations',
                    'Supervises prescription dispensations and emergency protocols',
                ]),
                'reports_to' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
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
                'responsibilities' => json_encode([
                    'Coordinates day-to-day clinic visits and patient intake',
                    'Manages student vital signs and immunization tracking',
                    'Supervises junior staff nurses and clinical interns',
                ]),
                'reports_to' => 'c-1',
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
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
                'responsibilities' => json_encode([
                    'First aid treatment and wound dressing',
                    'Patient vital signs recording and consultation logs',
                    'Assisting physician in student physical examinations',
                ]),
                'reports_to' => 'c-2',
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
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
                'responsibilities' => json_encode([
                    'Maternal and reproductive health counseling',
                    'Fetal Doppler monitoring and routine vitals assessment',
                    'Women\'s health wellness education seminars',
                ]),
                'reports_to' => 'c-2',
                'sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
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
                'responsibilities' => json_encode([
                    'Emergency trauma response on campus grounds',
                    'Maintenance of emergency kit, oxygen, and stretchers',
                    'CPR and emergency medical transport coordination',
                ]),
                'reports_to' => 'c-2',
                'sort_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
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
                'responsibilities' => json_encode([
                    'Medicine stock tracking, expiration audits & re-ordering',
                    'Safe prescription dispensing and dosage instructions',
                    'Inventory logging on the digital clinic management system',
                ]),
                'reports_to' => 'c-2',
                'sort_order' => 6,
                'created_at' => now(),
                'updated_at' => now(),
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
                'responsibilities' => json_encode([
                    'Maintenance of student digital and physical health folders',
                    'Processing medical certificates and clearance documents',
                    'Generation of monthly health statistics and clinic analytics',
                ]),
                'reports_to' => 'c-2',
                'sort_order' => 7,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed default admin members
        DB::table('organization_members')->insert([
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
                'responsibilities' => json_encode([
                    'Institutional direction and educational governance',
                    'Sanctioning campus health & safety operational budgets',
                    'Liaison with municipal government and healthcare partners',
                ]),
                'reports_to' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
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
                'responsibilities' => json_encode([
                    'Academic integration of campus healthcare programs',
                    'Institutional medical requirements for enrolled students',
                    'Faculty wellness and health development programs',
                ]),
                'reports_to' => 'a-1',
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
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
                'responsibilities' => json_encode([
                    'Supervision of nursing & midwifery clinical affiliations',
                    'Clinical curriculum and competency standards',
                    'Collaboration between academic department & campus clinic',
                ]),
                'reports_to' => 'a-2',
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
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
                'responsibilities' => json_encode([
                    'Student welfare and emergency crisis assistance',
                    'Campus-wide annual medical and dental checkup drives',
                    'Coordination of medical clearance for collegiate athletes',
                ]),
                'reports_to' => 'a-1',
                'sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organization_categories');
    }
};
