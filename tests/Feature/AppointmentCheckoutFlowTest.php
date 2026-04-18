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
}
