<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\TaxInvoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Tests\TestCase;

class ReportServiceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_filter_service_reports_by_customer_and_invoice_number(): void
    {
        $manager = $this->managerUser();
        $this->completedAppointmentWithInvoice('Aisha Khan', 'INV-2026-0007');
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
            );
    }

    public function test_service_report_rows_include_financial_amounts_from_invoice_items(): void
    {
        [$appointment, $invoice] = $this->completedAppointmentWithInvoice('Aisha Khan', 'INV-2026-0007');

        $invoice->items()->create([
            'salon_service_id' => $appointment->service_id,
            'description' => 'Hair Styling',
            'quantity' => 2,
            'unit_price' => 75,
            'discount_amount' => 10,
            'line_subtotal' => 140,
            'tax_rate_percent' => 5,
            'line_tax' => 7,
            'line_total' => 147,
        ]);

        $method = new ReflectionMethod(ReportController::class, 'collectServiceReportRows');
        $method->setAccessible(true);

        $rows = $method->invoke(app(ReportController::class), Carbon::parse('2026-05-21')->startOfDay(), Carbon::parse('2026-05-21')->endOfDay(), [
            'customer_name' => 'Aisha',
            'invoice_number' => '0007',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame($appointment->id, $rows[0]['id']);
        $this->assertSame('INV-2026-0007', $rows[0]['invoice_number']);
        $this->assertSame(2.0, $rows[0]['quantity']);
        $this->assertSame(75.0, $rows[0]['unit_price']);
        $this->assertSame(10.0, $rows[0]['discount_amount']);
        $this->assertSame(140.0, $rows[0]['subtotal']);
        $this->assertSame(7.0, $rows[0]['tax']);
        $this->assertSame(147.0, $rows[0]['total']);
    }

    public function test_service_report_uses_visit_invoice_line_when_invoice_service_variant_was_changed(): void
    {
        [$firstAppointment, $invoice] = $this->completedAppointmentWithInvoice('Maryam Albooshi', 'RCT00033');

        $firstAppointment->service->update([
            'name' => 'Blowdry Curly/wavy w/ Iron Medium',
            'price' => 150,
        ]);

        $longService = SalonService::create([
            'name' => 'Blowdry Curly/wavy w/ Iron Long',
            'category' => 'Hair',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 175,
            'is_active' => true,
        ]);
        $fullHair = SalonService::create([
            'name' => 'Full hair',
            'category' => 'Hair',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 425,
            'is_active' => true,
        ]);
        $visitId = 'visit-rct00033';

        $firstAppointment->update(['visit_id' => $visitId]);
        $secondAppointment = Appointment::create([
            'customer_id' => $firstAppointment->customer_id,
            'service_id' => $fullHair->id,
            'staff_profile_id' => $firstAppointment->staff_profile_id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => '2026-05-21 18:45:00',
            'scheduled_end' => '2026-05-21 19:45:00',
            'customer_name' => $firstAppointment->customer_name,
            'customer_phone' => $firstAppointment->customer_phone,
            'notes' => 'done',
            'visit_id' => $visitId,
        ]);

        $invoice->update([
            'appointment_id' => $firstAppointment->id,
            'subtotal' => 600,
            'vat_amount' => 30,
            'total' => 630,
        ]);
        $invoice->items()->create([
            'salon_service_id' => $longService->id,
            'description' => 'Blowdry Curly/wavy w/ Iron Long',
            'quantity' => 1,
            'unit_price' => 175,
            'discount_amount' => 0,
            'line_subtotal' => 175,
            'tax_rate_percent' => 5,
            'line_tax' => 8.75,
            'line_total' => 183.75,
        ]);
        $invoice->items()->create([
            'salon_service_id' => $fullHair->id,
            'description' => 'Full hair',
            'quantity' => 1,
            'unit_price' => 425,
            'discount_amount' => 0,
            'line_subtotal' => 425,
            'tax_rate_percent' => 5,
            'line_tax' => 21.25,
            'line_total' => 446.25,
        ]);

        $method = new ReflectionMethod(ReportController::class, 'collectServiceReportRows');
        $method->setAccessible(true);

        $rows = collect($method->invoke(app(ReportController::class), Carbon::parse('2026-05-21')->startOfDay(), Carbon::parse('2026-05-21')->endOfDay(), [
            'customer_name' => 'Maryam',
            'invoice_number' => 'RCT00033',
        ]))->keyBy('id');

        $this->assertSame(175.0, $rows[$firstAppointment->id]['unit_price']);
        $this->assertSame(175.0, $rows[$firstAppointment->id]['subtotal']);
        $this->assertSame(8.75, $rows[$firstAppointment->id]['tax']);
        $this->assertSame(183.75, $rows[$firstAppointment->id]['total']);
        $this->assertSame(425.0, $rows[$secondAppointment->id]['subtotal']);
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
