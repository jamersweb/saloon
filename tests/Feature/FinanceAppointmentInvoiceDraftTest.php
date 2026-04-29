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
use Carbon\Carbon;
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

    public function test_multi_service_visit_creates_invoice_with_all_visit_services(): void
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
            'employee_code' => 'OWN-FIN-3',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'FIN-APT-3',
            'name' => 'Teresa',
            'phone' => '5559992222',
            'is_active' => true,
        ]);

        $threading = SalonService::create([
            'name' => 'Threading Eyebrow',
            'category' => 'Threading',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 60,
            'is_active' => true,
        ]);

        $manicure = SalonService::create([
            'name' => 'Basic Manicure',
            'category' => 'Nails',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 75,
            'is_active' => true,
        ]);

        $createdAt = Carbon::parse('2026-04-29 13:00:00');

        $first = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $threading->id,
            'staff_profile_id' => $staffProfile->id,
            'booked_by' => $owner->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->subMinutes(30),
            'arrival_time' => now()->subHour(),
            'service_start_time' => now()->subMinutes(50),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $second = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $manicure->id,
            'staff_profile_id' => $staffProfile->id,
            'booked_by' => $owner->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subMinutes(30),
            'scheduled_end' => now(),
            'arrival_time' => now()->subHour(),
            'service_start_time' => now()->subMinutes(35),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->actingAs($owner)
            ->post(route('appointments.service-complete', $first), [
                'service_report' => 'Visit finished.',
                'create_tax_invoice_draft' => true,
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $invoice = TaxInvoice::query()->where('appointment_id', $first->id)->firstOrFail();

        $this->assertCount(2, $invoice->items);
        $this->assertSame(
            ['Threading Eyebrow', 'Basic Manicure'],
            $invoice->items()->orderBy('id')->pluck('description')->all()
        );
        $this->assertSame('Created from visit appointments #'.$first->id.', #'.$second->id, $invoice->notes);
    }

    public function test_second_service_completion_reuses_same_draft_and_syncs_all_visit_lines(): void
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
            'employee_code' => 'OWN-FIN-4',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'FIN-APT-4',
            'name' => 'Nadia',
            'phone' => '5559993333',
            'is_active' => true,
        ]);

        $serviceOne = SalonService::create([
            'name' => 'Eyelash Refill',
            'category' => 'Lashes',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 120,
            'is_active' => true,
        ]);

        $serviceTwo = SalonService::create([
            'name' => 'Acrylic Gel Refill',
            'category' => 'Nails',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 180,
            'is_active' => true,
        ]);

        $visitId = (string) \Illuminate\Support\Str::uuid();

        $first = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $serviceOne->id,
            'staff_profile_id' => $staffProfile->id,
            'visit_id' => $visitId,
            'booked_by' => $owner->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subHour(),
            'arrival_time' => now()->subHours(2),
            'service_start_time' => now()->subHours(2),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        $second = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $serviceTwo->id,
            'staff_profile_id' => $staffProfile->id,
            'visit_id' => $visitId,
            'booked_by' => $owner->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now(),
            'arrival_time' => now()->subHours(2),
            'service_start_time' => now()->subMinutes(50),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        $this->actingAs($owner)
            ->post(route('appointments.service-complete', $first), [
                'service_report' => 'First service done.',
                'create_tax_invoice_draft' => true,
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $firstInvoice = TaxInvoice::query()->where('appointment_id', $first->id)->firstOrFail();
        $this->assertCount(2, $firstInvoice->items);

        $this->actingAs($owner)
            ->post(route('appointments.service-complete', $second), [
                'service_report' => 'Second service done.',
                'create_tax_invoice_draft' => true,
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, TaxInvoice::query()->count());

        $invoice = TaxInvoice::query()->firstOrFail();
        $this->assertSame($firstInvoice->id, $invoice->id);
        $this->assertEqualsCanonicalizing(
            ['Eyelash Refill', 'Acrylic Gel Refill'],
            $invoice->items()->pluck('description')->all()
        );
        $this->assertSame(315.0, (float) $invoice->total);
    }
}
