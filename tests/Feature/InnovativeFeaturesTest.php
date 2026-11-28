<?php

namespace Tests\Feature;

use App\Models\ClinicVisit;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineTransaction;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InnovativeFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_resolve_and_patient_timeline(): void
    {
        $nurse = User::factory()->create(['role' => 'Nurse']);
        Sanctum::actingAs($nurse);

        $student = Student::create([
            'student_id' => '20261234',
            'first_name' => 'Clara',
            'last_name' => 'Santos',
            'course' => 'BSIT',
            'year_level' => '2nd Year',
            'section' => 'A',
            'sex' => 'Female',
            'birth_date' => '2005-06-15',
            'contact_number' => '09123456789',
            'address' => 'Tagoloan',
        ]);

        ClinicVisit::create([
            'student_id' => $student->id,
            'nurse_id' => $nurse->id,
            'visit_date' => now()->toDateString(),
            'reason' => 'Headache',
            'temperature' => '37.2 °C',
            'follow_up_date' => now()->addDays(3)->toDateString(),
            'follow_up_status' => 'pending',
        ]);

        // Resolve by institutional ID
        $qrRes = $this->getJson('/api/patients/qr-resolve?code=20261234');
        $qrRes->assertOk()->assertJsonPath('found', true)->assertJsonPath('patient.id', $student->id);
        $token = $qrRes->json('qr_token');

        // Resolve by signed QR token
        $this->getJson('/api/patients/qr-resolve?code='.urlencode($token))
            ->assertOk()
            ->assertJsonPath('patient.identifier', '20261234');

        // Tampered token rejected
        $this->getJson('/api/patients/qr-resolve?code=TCMS1:student:1:badsignature')->assertStatus(422);

        // Timeline returns visit with N/A for missing fields
        $this->getJson("/api/patients/student/{$student->id}/timeline")
            ->assertOk()
            ->assertJsonPath('total_visits', 1)
            ->assertJsonPath('timeline.0.blood_pressure', 'N/A');
    }

    public function test_digital_queue_and_follow_ups_and_trends(): void
    {
        $nurse = User::factory()->create(['role' => 'Nurse']);
        Sanctum::actingAs($nurse);

        $student = Student::create([
            'student_id' => '20265555',
            'first_name' => 'Juan',
            'last_name' => 'Luna',
            'course' => 'BSBA',
            'year_level' => '1st Year',
            'section' => 'B',
            'sex' => 'Male',
            'birth_date' => '2006-04-12',
            'contact_number' => '09123456789',
            'address' => 'Tagoloan',
        ]);

        // Queue check-in
        $qRes = $this->postJson('/api/queue', [
            'patient_type' => 'student',
            'patient_id' => $student->id,
            'priority' => 'Urgent',
            'chief_complaint' => 'Fever',
        ]);
        $qRes->assertCreated();
        $qId = $qRes->json('queue_entry.id');

        $this->getJson('/api/queue')->assertOk()->assertJsonPath('counts.waiting', 1);
        $this->patchJson("/api/queue/{$qId}/status", ['status' => 'In Consultation'])->assertOk();
        $this->patchJson("/api/queue/{$qId}/status", ['status' => 'Completed'])->assertOk();

        // Follow-ups
        $visit = ClinicVisit::create([
            'student_id' => $student->id,
            'nurse_id' => $nurse->id,
            'visit_date' => now()->toDateString(),
            'reason' => 'Fever',
            'follow_up_date' => now()->subDay()->toDateString(),
            'follow_up_status' => 'pending',
        ]);

        $this->getJson('/api/follow-ups')->assertOk()->assertJsonPath('summary.overdue', 1);
        $this->patchJson("/api/clinic-visits/{$visit->id}/follow-up", ['follow_up_status' => 'completed'])->assertOk();

        // Health trends
        $this->getJson('/api/reports/health-trends')
            ->assertOk()
            ->assertJsonStructure(['disclaimer', 'period', 'executive_summary', 'alerts']);
    }

    public function test_expiry_intelligence_forecasting_and_audit_logs_rbac(): void
    {
        $nurse = User::factory()->create(['role' => 'Nurse']);
        $admin = User::factory()->create(['role' => 'Admin']);

        $med = Medicine::create([
            'medicine_name' => 'Ibuprofen 400mg',
            'treatment_type' => 'Medication',
            'unit' => 'Tablet',
            'stock' => 25,
            'minimum_stock' => 10,
        ]);

        MedicineBatch::create([
            'medicine_id' => $med->id,
            'batch_number' => 'B-SOON',
            'initial_quantity' => 25,
            'current_quantity' => 25,
            'date_added' => now()->subDays(10)->toDateString(),
            'expiration_date' => now()->addDays(15)->toDateString(),
        ]);

        MedicineTransaction::create([
            'medicine_id' => $med->id,
            'transaction_type' => 'dispense',
            'quantity' => 10,
            'performed_by' => $nurse->id,
        ]);

        Sanctum::actingAs($nurse);
        $this->getJson('/api/medicines/expiry-intelligence')
            ->assertOk()
            ->assertJsonPath('summary.expiring_30d_batches', 1);

        $this->getJson('/api/medicines/forecast?days=30')
            ->assertOk()
            ->assertJsonPath('items.0.insufficient_data', true);

        // Nurse cannot view audit logs (Admin only)
        $this->getJson('/api/audit-logs')->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->getJson('/api/audit-logs')->assertOk();
    }
}
