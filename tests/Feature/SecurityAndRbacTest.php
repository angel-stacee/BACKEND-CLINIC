<?php

namespace Tests\Feature;

use App\Models\ClinicVisit;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityAndRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_debug_endpoints_are_removed(): void
    {
        $this->getJson('/api/init-db')->assertNotFound();
        $this->getJson('/api/test-students')->assertNotFound();
        $this->getJson('/api/db-info')->assertNotFound();
    }

    public function test_login_rejects_backdoor_passwords_and_does_not_auto_create_accounts(): void
    {
        $user = User::factory()->create([
            'email' => 'nurse@tcc.edu.ph',
            'role' => 'Nurse',
            'password' => Hash::make('RealSecurePass!99'),
        ]);

        // Backdoor password must fail
        $this->postJson('/api/login', [
            'email' => 'nurse@tcc.edu.ph',
            'password' => 'password123',
        ])->assertStatus(401);

        // Password hash must NOT have been overwritten
        $this->assertTrue(Hash::check('RealSecurePass!99', $user->fresh()->password));

        // Auto-created 'nonexistent-staff' account must NOT be created on login
        $this->postJson('/api/login', [
            'email' => 'nonexistent.staff@tcc.edu.ph',
            'password' => 'password123',
        ])->assertStatus(401);
        $this->assertDatabaseMissing('users', ['email' => 'nonexistent.staff@tcc.edu.ph']);
    }

    public function test_password_reset_rejects_hardcoded_master_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'nurse2@tcc.edu.ph',
            'role' => 'Nurse',
            'password' => Hash::make('OriginalPass!123'),
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'nurse2@tcc.edu.ph',
            'token' => Hash::make('987654'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/forgot-password/verify-otp', [
            'email' => 'nurse2@tcc.edu.ph',
            'otp' => '123456',
        ])->assertStatus(400);

        $this->postJson('/api/forgot-password/reset', [
            'email' => 'nurse2@tcc.edu.ph',
            'otp' => '123456',
            'password' => 'NewHackPass!123',
            'password_confirmation' => 'NewHackPass!123',
        ])->assertStatus(400);

        $this->assertTrue(Hash::check('OriginalPass!123', $user->fresh()->password));
    }

    public function test_rbac_enforces_role_permissions(): void
    {
        $student = Student::create([
            'student_id' => '2026-9991',
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'course' => 'BSIT',
            'year_level' => '2nd Year',
            'section' => 'A',
            'sex' => 'Female',
            'birth_date' => '2005-03-10',
            'contact_number' => '09123456780',
            'address' => 'Tagoloan',
        ]);
        $student->delete();

        $staffUser = User::factory()->create(['role' => 'Staff']);
        $nurseUser = User::factory()->create(['role' => 'Nurse']);
        $adminUser = User::factory()->create(['role' => 'Admin']);
        $viewerUser = User::factory()->create(['role' => 'Student']);

        // Unauthorized role gets 403
        Sanctum::actingAs($viewerUser);
        $this->getJson('/api/students')->assertStatus(403);

        // Staff cannot dispense medicine or force delete
        Sanctum::actingAs($staffUser);
        $this->getJson('/api/students')->assertOk();
        $this->postJson('/api/medicines/dispense', [])->assertStatus(403);
        $this->deleteJson("/api/students/{$student->id}/force")->assertStatus(403);

        // Nurse cannot forceDelete or reset organization
        Sanctum::actingAs($nurseUser);
        $this->deleteJson("/api/students/{$student->id}/force")->assertStatus(403);
        $this->postJson('/api/organization/reset')->assertStatus(403);

        // Admin can forceDelete
        Sanctum::actingAs($adminUser);
        $this->deleteJson("/api/students/{$student->id}/force")->assertOk();
    }

    public function test_soft_deleted_student_id_can_be_re_registered(): void
    {
        $nurse = User::factory()->create(['role' => 'Nurse']);
        Sanctum::actingAs($nurse);

        $student = Student::create([
            'student_id' => '20268888',
            'first_name' => 'Mark',
            'last_name' => 'Cruz',
            'course' => 'BSIT',
            'year_level' => '1st Year',
            'section' => 'A',
            'sex' => 'Male',
            'birth_date' => '2006-01-01',
            'contact_number' => '09123456789',
            'address' => 'Tagoloan',
        ]);

        $student->delete();

        $res = $this->postJson('/api/students', [
            'student_id' => '20268888',
            'first_name' => 'Mark',
            'last_name' => 'Cruz Jr',
            'course' => 'BSIT',
            'year_level' => '1st Year',
            'section' => 'A',
            'sex' => 'Male',
            'birth_date' => '2006-01-01',
            'contact_number' => '09123456789',
            'address' => 'Tagoloan',
        ]);

        $res->assertCreated();
    }

    public function test_restoring_clinic_visit_deducts_batch_stock_via_inventory_service(): void
    {
        $nurse = User::factory()->create(['role' => 'Nurse']);
        Sanctum::actingAs($nurse);

        $student = Student::create([
            'student_id' => '2026-7777',
            'first_name' => 'Leo',
            'last_name' => 'Tan',
            'course' => 'BSIT',
            'year_level' => '1st Year',
            'section' => 'A',
            'sex' => 'Male',
            'birth_date' => '2006-02-02',
            'contact_number' => '09123456789',
            'address' => 'Tagoloan',
        ]);

        $medicine = Medicine::create([
            'medicine_name' => 'Cetirizine 10mg',
            'treatment_type' => 'Medication',
            'unit' => 'Tablet',
            'stock' => 20,
            'minimum_stock' => 5,
            'expiration_date' => now()->addMonths(6)->toDateString(),
        ]);

        $batch = MedicineBatch::create([
            'medicine_id' => $medicine->id,
            'batch_number' => 'BATCH-001',
            'initial_quantity' => 20,
            'current_quantity' => 20,
            'date_added' => now()->toDateString(),
            'expiration_date' => now()->addMonths(6)->toDateString(),
        ]);

        $visitRes = $this->postJson('/api/clinic-visits', [
            'patient_type' => 'student',
            'student_id' => $student->id,
            'nurse_id' => $nurse->id,
            'visit_date' => now()->toDateString(),
            'reason' => 'Allergy',
            'medicine_id' => $medicine->id,
            'medicine_quantity' => 3,
        ]);
        $visitRes->assertCreated();
        $visitId = $visitRes->json('visit.id') ?? $visitRes->json('clinic_visit.id') ?? $visitRes->json('id') ?? ClinicVisit::latest('id')->value('id');

        $this->assertEquals(17, $batch->fresh()->current_quantity);

        // Delete visit -> restores stock to 20
        $this->deleteJson("/api/clinic-visits/{$visitId}")->assertOk();
        $this->assertEquals(20, $batch->fresh()->current_quantity);

        // Restore visit -> deducts 3 again via MedicineInventoryService
        $this->postJson("/api/clinic-visits/{$visitId}/restore")->assertOk();
        $this->assertEquals(17, $batch->fresh()->current_quantity);
        $this->assertEquals(17, $medicine->fresh()->stock);
    }
}
