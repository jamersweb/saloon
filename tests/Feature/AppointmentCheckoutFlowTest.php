<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\FinanceSetting;
use App\Models\GiftCard;
use App\Models\InvoicePayment;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\TaxInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppointmentCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedReceptionUser(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'reception'],
            ['label' => 'Reception', 'permissions' => []],
        );

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }

    private function inProgressAppointment(User $actor): Appointment
    {
        FinanceSetting::current();

        $staffProfile = StaffProfile::create([
            'user_id' => $actor->id,
            'employee_code' => 'CHK-1',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'CHK-CUST-1',
            'name' => 'Paying Client',
            'phone' => '5551112233',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Cut',
            'category' => 'Hair',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 100,
            'is_active' => true,
        ]);

        return Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now(),
            'arrival_time' => now()->subHour(),
            'service_start_time' => now()->subMinutes(20),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);
    }

    public function test_reception_can_complete_visit_with_tax_invoice_draft_by_default(): void
    {
        $reception = $this->seedReceptionUser();
        $appointment = $this->inProgressAppointment($reception);

        $this->actingAs($reception)
            ->post(route('appointments.service-complete', $appointment), [
                'service_report' => 'Done.',
                'create_tax_invoice_draft' => true,
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $invoice = TaxInvoice::query()->where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(TaxInvoice::STATUS_DRAFT, $invoice->status);
    }

    public function test_reception_can_finish_and_pay_with_cash_in_one_step(): void
    {
        $reception = $this->seedReceptionUser();
        $appointment = $this->inProgressAppointment($reception);

        $this->actingAs($reception)
            ->post(route('appointments.service-complete', $appointment), [
                'service_report' => 'Done.',
                'finish_and_pay' => true,
                'checkout_payment_method' => InvoicePayment::METHOD_CASH,
                'checkout_paid_at' => now()->format('Y-m-d\TH:i'),
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->status);

        $invoice = TaxInvoice::query()->where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(TaxInvoice::STATUS_FINALIZED, $invoice->status);
        $this->assertGreaterThan(0, $invoice->amountPaid());
        $this->assertLessThan(0.02, $invoice->balanceDue());
    }

    public function test_reception_can_finish_and_pay_with_gift_card(): void
    {
        $reception = $this->seedReceptionUser();
        $appointment = $this->inProgressAppointment($reception);
        $customer = $appointment->customer;

        GiftCard::create([
            'code' => 'GIFT-CHK-TEST-1',
            'assigned_customer_id' => $customer->id,
            'initial_value' => 500,
            'remaining_value' => 500,
            'status' => 'active',
            'issued_by' => $reception->id,
        ]);
        $gift = GiftCard::query()->where('code', 'GIFT-CHK-TEST-1')->firstOrFail();

        $this->actingAs($reception)
            ->post(route('appointments.service-complete', $appointment), [
                'service_report' => 'Done.',
                'finish_and_pay' => true,
                'checkout_payment_method' => InvoicePayment::METHOD_GIFT_CARD,
                'checkout_gift_card_id' => $gift->id,
                'checkout_paid_at' => now()->format('Y-m-d\TH:i'),
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $gift->refresh();
        $this->assertLessThan(500.0, (float) $gift->remaining_value);

        $invoice = TaxInvoice::query()->where('appointment_id', $appointment->id)->first();
        $this->assertSame(TaxInvoice::STATUS_FINALIZED, $invoice->status);
        $this->assertEqualsWithDelta(100.0, (float) $invoice->subtotal, 0.02);
        $this->assertEqualsWithDelta(5.0, (float) $invoice->vat_amount, 0.02);
        $this->assertEqualsWithDelta(105.0, (float) $invoice->total, 0.02);
        $this->assertLessThan(0.02, $invoice->balanceDue());
    }

    public function test_reception_can_open_visit_linked_invoice(): void
    {
        $reception = $this->seedReceptionUser();
        $appointment = $this->inProgressAppointment($reception);

        $this->actingAs($reception)
            ->post(route('appointments.service-complete', $appointment), [
                'service_report' => 'Done.',
                'create_tax_invoice_draft' => true,
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $invoice = TaxInvoice::query()->where('appointment_id', $appointment->id)->firstOrFail();

        $this->actingAs($reception)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk();
    }

    public function test_opening_existing_draft_refreshes_missing_multi_service_visit_lines(): void
    {
        $reception = $this->seedReceptionUser();
        FinanceSetting::current();

        $staffProfile = StaffProfile::create([
            'user_id' => $reception->id,
            'employee_code' => 'CHK-MULTI-1',
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'customer_code' => 'CHK-CUST-MULTI',
            'name' => 'Multi Service Client',
            'phone' => '5551117777',
            'is_active' => true,
        ]);
        $services = collect(['Blowdry Curly/wavy w/ Iron Short', 'Acrylic powder refill', 'Baby Polish'])
            ->map(fn (string $name, int $index) => SalonService::create([
                'name' => $name,
                'category' => 'Hair',
                'duration_minutes' => 30,
                'buffer_minutes' => 0,
                'price' => 100 + ($index * 25),
                'is_active' => true,
            ]));
        $visitId = (string) Str::uuid();

        $appointments = $services->values()->map(fn (SalonService $service, int $index) => Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'visit_id' => $visitId,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subHours(2)->addMinutes($index * 30),
            'scheduled_end' => now()->subHours(2)->addMinutes(($index + 1) * 30),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]));
        $primaryAppointment = $appointments->first();

        $invoice = TaxInvoice::create([
            'customer_id' => $customer->id,
            'customer_display_name' => $customer->name,
            'status' => TaxInvoice::STATUS_DRAFT,
            'appointment_id' => $primaryAppointment->id,
            'subtotal' => 100,
            'vat_amount' => 5,
            'total' => 105,
            'cashier_name' => $reception->name,
            'created_by' => $reception->id,
            'notes' => 'Created from appointment #'.$primaryAppointment->id,
        ]);
        $invoice->items()->create([
            'salon_service_id' => $services->first()->id,
            'description' => $services->first()->name,
            'quantity' => 1,
            'unit_price' => 100,
            'line_subtotal' => 100,
            'tax_rate_percent' => 5,
            'line_tax' => 5,
            'line_total' => 105,
        ]);

        $this->actingAs($reception)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Invoices/Show')
                ->has('invoice.items', 3)
                ->where('invoice.items.0.description', 'Blowdry Curly/wavy w/ Iron Short')
                ->where('invoice.items.1.description', 'Acrylic powder refill')
                ->where('invoice.items.2.description', 'Baby Polish')
            );
    }

    public function test_reception_can_open_checkout_for_completed_visit_that_has_no_invoice_yet(): void
    {
        $reception = $this->seedReceptionUser();
        $appointment = $this->inProgressAppointment($reception);

        $this->actingAs($reception)
            ->post(route('appointments.service-complete', $appointment), [
                'service_report' => 'Done.',
                'create_tax_invoice_draft' => false,
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->status);
        $this->assertDatabaseCount('tax_invoices', 0);

        $response = $this->actingAs($reception)
            ->post(route('appointments.checkout', $appointment));

        $invoice = TaxInvoice::query()->where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(TaxInvoice::STATUS_DRAFT, $invoice->status);
        $response->assertRedirect(route('finance.invoices.show', $invoice, false));
    }
}
