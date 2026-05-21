<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\TaxInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportServiceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_filter_service_reports_by_customer_and_invoice_number(): void
    {
        $manager = $this->managerUser();
        [$appointment] = $this->completedAppointmentWithInvoice('Aisha Khan', 'INV-2026-0007');
        $this->completedAppointmentWithInvoice('Other Customer', 'INV-2026-0008');

        $this->actingAs($manager)
            ->get(route('reports.index', [
                'date_from' => '2026-05-21',
                'date_to' => '2026-05-21',
                'customer_name' => 'Aisha',
                'invoice_number' => '0007',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('filters.customer_name', 'Aisha')
                ->where('filters.invoice_number', '0007')
                ->has('serviceReports', 1)
                ->where('serviceReports.0.id', $appointment->id)
                ->where('serviceReports.0.customer_name', 'Aisha Khan')
                ->where('serviceReports.0.invoice_number', 'INV-2026-0007')
                ->where('serviceReports.0.service_report', 'Client requested soft layers and a blowdry finish.')
            );
    }

    public function test_appointments_csv_export_includes_invoice_number_and_service_report(): void
    {
        $manager = $this->managerUser();
        $this->completedAppointmentWithInvoice('Aisha Khan', 'INV-2026-0007');

        $response = $this->actingAs($manager)
            ->get(route('reports.export', [
                'type' => 'appointments',
                'date_from' => '2026-05-21',
                'date_to' => '2026-05-21',
            ]));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Invoice No.', $csv);
        $this->assertStringContainsString('Service Report', $csv);
        $this->assertStringContainsString('INV-2026-0007', $csv);
        $this->assertStringContainsString('Client requested soft layers and a blowdry finish.', $csv);
    }

    /**
     * @return array{Appointment, TaxInvoice}
     */
    private function completedAppointmentWithInvoice(string $customerName, string $invoiceNumber): array
    {
        static $sequence = 1;

        $staffUser = User::factory()->create(['name' => 'Nadia Stylist']);
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'SR-'.$sequence++,
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'customer_code' => 'SR-'.str_replace([' ', '-'], '', strtoupper($customerName)),
            'name' => $customerName,
            'phone' => '5551112233',
            'is_active' => true,
        ]);
        $service = SalonService::create([
            'name' => 'Hair Styling',
            'category' => 'Hair',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 120,
            'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => '2026-05-21 18:15:00',
            'scheduled_end' => '2026-05-21 19:00:00',
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'notes' => 'Client requested soft layers and a blowdry finish.',
        ]);
        $invoice = TaxInvoice::create([
            'invoice_number' => $invoiceNumber,
            'customer_id' => $customer->id,
            'customer_display_name' => $customer->name,
            'status' => TaxInvoice::STATUS_FINALIZED,
            'appointment_id' => $appointment->id,
            'subtotal' => 120,
            'vat_amount' => 6,
            'total' => 126,
            'issued_at' => '2026-05-21 19:05:00',
        ]);

        return [$appointment, $invoice];
    }

    private function managerUser(): User
    {
        $role = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
        ]);

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }
}
