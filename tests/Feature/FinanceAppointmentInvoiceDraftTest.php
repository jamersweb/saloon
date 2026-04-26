<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\FinanceSetting;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\ServicePackage;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\PackageBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceAppointmentInvoiceDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_visit_with_option_creates_linked_tax_invoice_draft(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        FinanceSetting::current();

        $staffProfile = StaffProfile::create([
            'user_id' => $owner->id,
            'employee_code' => 'OWN-FIN-1',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'FIN-APT-1',
            'name' => 'Walkin Client',
            'phone' => '5559990000',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Styling',
            'category' => 'Hair',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 200,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now(),
            'arrival_time' => now()->subHour(),
            'service_start_time' => now()->subMinutes(30),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        $this->actingAs($owner)
            ->post(route('appointments.service-complete', $appointment), [
                'service_report' => 'Service done.',
                'create_tax_invoice_draft' => true,
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->status);

        $invoice = TaxInvoice::query()->where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(TaxInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('Styling', $invoice->items->first()->description);
    }

    public function test_package_covered_appointment_creates_zero_priced_service_line_and_consumes_session(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        FinanceSetting::current();

        $staffProfile = StaffProfile::create([
            'user_id' => $owner->id,
            'employee_code' => 'OWN-FIN-2',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'FIN-APT-2',
            'name' => 'Package Client',
            'phone' => '5559991111',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Root Color',
            'category' => 'Hair',
            'duration_minutes' => 90,
            'buffer_minutes' => 0,
            'price' => 250,
            'is_active' => true,
        ]);

        $package = ServicePackage::create([
            'name' => 'Color Package',
            'price' => 800,
            'usage_limit' => 4,
            'is_active' => true,
        ]);
        $package->salonServices()->sync([$service->id => ['included_sessions' => 4]]);

        $customerPackage = app(PackageBalanceService::class)->assignPackage($customer, $package);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'customer_package_id' => $customerPackage->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now(),
            'arrival_time' => now()->subHour(),
            'service_start_time' => now()->subMinutes(30),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        $this->actingAs($owner)
            ->post(route('appointments.service-complete', $appointment), [
                'service_report' => 'Package service done.',
                'create_tax_invoice_draft' => true,
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $appointment->refresh();
        $customerPackage->refresh();

        $invoice = TaxInvoice::query()->where('appointment_id', $appointment->id)->firstOrFail();
        $serviceLine = $invoice->items->first();

        $this->assertTrue($appointment->package_session_applied);
        $this->assertSame('0.00', $serviceLine->unit_price);
        $this->assertStringContainsString('package session', $serviceLine->description);
        $this->assertSame(3, $customerPackage->remaining_sessions);
        $this->assertDatabaseHas('customer_package_usages', [
            'customer_package_id' => $customerPackage->id,
            'appointment_id' => $appointment->id,
            'salon_service_id' => $service->id,
            'sessions_used' => 1,
        ]);
    }
}
