<?php

namespace Tests\Feature;

use App\Mail\TaxInvoiceReceiptMail;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\FinanceSetting;
use App\Models\GiftCard;
use App\Models\InvoicePayment;
use App\Models\MembershipCardType;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\TaxInvoice;
use App\Support\FinanceStructure;
use App\Models\User;
use App\Support\TaxReceiptPdfView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FinanceTaxInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_finalize_and_record_payment_with_vat(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $user = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        FinanceSetting::current();

        $staff = StaffProfile::create([
            'user_id' => $user->id,
            'employee_code' => 'FIN-STAFF-1',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'FIN-C1',
            'name' => 'Soraya',
            'phone' => '5551112233',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Extension',
            'category' => 'Hair',
            'duration_minutes' => 120,
            'buffer_minutes' => 0,
            'price' => 550,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('finance.invoices.store'), [
                'customer_id' => $customer->id,
                'customer_display_name' => $customer->name,
                'items' => [
                    [
                        'salon_service_id' => $service->id,
                        'staff_profile_id' => $staff->id,
                        'description' => $service->name,
                        'quantity' => 1,
                        'unit_price' => 550,
                        'discount_amount' => 50,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invoice = TaxInvoice::query()->latest()->first();
        $this->assertNotNull($invoice);
        $this->assertSame(TaxInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame($staff->id, $invoice->items()->first()->staff_profile_id);
        $this->assertEqualsWithDelta(500.0, (float) $invoice->subtotal, 0.02);
        $this->assertEqualsWithDelta(25.0, (float) $invoice->vat_amount, 0.02);
        $this->assertEqualsWithDelta(525.0, (float) $invoice->total, 0.02);

        $this->actingAs($user)
            ->post(route('finance.invoices.finalize', $invoice))
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(TaxInvoice::STATUS_FINALIZED, $invoice->status);
        $this->assertNotNull($invoice->invoice_number);

        $this->actingAs($user)
            ->post(route('finance.invoices.payments.store', $invoice), [
                'amount' => 525,
                'method' => 'cash',
                'paid_at' => now()->toDateTimeString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertLessThan(0.02, $invoice->fresh()->balanceDue());
    }

    public function test_owner_can_email_finalized_receipt(): void
    {
        Mail::fake();

        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $user = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        FinanceSetting::current();

        $customer = Customer::create([
            'customer_code' => 'FIN-C2',
            'name' => 'Email Customer',
            'phone' => '5551113344',
            'email' => 'client@example.com',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Cut',
            'category' => 'Hair',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 100,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('finance.invoices.store'), [
                'customer_id' => $customer->id,
                'customer_display_name' => $customer->name,
                'items' => [
                    [
                        'salon_service_id' => $service->id,
                        'description' => $service->name,
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount_amount' => 10,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $invoice = TaxInvoice::query()->latest()->first();
        $this->assertNotNull($invoice);

        $this->actingAs($user)
            ->post(route('finance.invoices.finalize', $invoice))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('finance.invoices.email-receipt', $invoice->fresh()), [
                'recipient_email' => 'guest@example.com',
            ])
            ->assertSessionHasNoErrors();

        Mail::assertSent(TaxInvoiceReceiptMail::class, function (TaxInvoiceReceiptMail $mail) {
            return $mail->hasTo('guest@example.com');
        });
    }

    public function test_assigned_gift_voucher_auto_deducts_before_cash_payment_when_invoice_total_reaches_minimum(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);
        $user = User::factory()->create(['role_id' => $ownerRole->id]);

        FinanceSetting::current();

        $customer = Customer::create([
            'customer_code' => 'FIN-VOUCHER-1',
            'name' => 'Voucher Invoice Customer',
            'phone' => '5553332211',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Color Package',
            'category' => 'Hair',
            'duration_minutes' => 120,
            'buffer_minutes' => 0,
            'price' => 400,
            'is_active' => true,
        ]);

        $giftCard = GiftCard::create([
            'code' => 'GIFT-AUTO-100',
            'assigned_customer_id' => $customer->id,
            'initial_value' => 100,
            'remaining_value' => 100,
            'status' => 'active',
            'issued_by' => $user->id,
            'notes' => 'Random gift voucher.',
        ]);

        $this->actingAs($user)->post(route('finance.invoices.store'), [
            'customer_id' => $customer->id,
            'customer_display_name' => $customer->name,
            'items' => [[
                'salon_service_id' => $service->id,
                'description' => $service->name,
                'quantity' => 1,
                'unit_price' => 400,
                'discount_amount' => 0,
            ]],
        ])->assertSessionHasNoErrors();

        $invoice = TaxInvoice::query()->latest()->firstOrFail();
        $this->actingAs($user)->post(route('finance.invoices.finalize', $invoice))->assertSessionHasNoErrors();
        $invoice->refresh();

        $this->actingAs($user)->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => $invoice->total,
            'method' => InvoicePayment::METHOD_CASH,
            'paid_at' => now()->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $payments = $invoice->fresh()->payments()->orderBy('id')->get();

        $this->assertCount(2, $payments);
        $this->assertSame(InvoicePayment::METHOD_GIFT_CARD, $payments[0]->method);
        $this->assertEqualsWithDelta(100.0, (float) $payments[0]->amount, 0.02);
        $this->assertSame(InvoicePayment::METHOD_CASH, $payments[1]->method);
        $this->assertEqualsWithDelta((float) $invoice->total - 100.0, (float) $payments[1]->amount, 0.02);
        $this->assertLessThan(0.02, $invoice->fresh()->balanceDue());
        $this->assertSame('0.00', $giftCard->fresh()->remaining_value);
        $this->assertSame('redeemed', $giftCard->fresh()->status);
    }

    public function test_assigned_gift_voucher_does_not_auto_deduct_when_invoice_total_is_below_minimum(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);
        $user = User::factory()->create(['role_id' => $ownerRole->id]);

        FinanceSetting::current();

        $customer = Customer::create([
            'customer_code' => 'FIN-VOUCHER-LOW',
            'name' => 'Low Total Voucher Customer',
            'phone' => '5553332299',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Blowdry',
            'category' => 'Hair',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 200,
            'is_active' => true,
        ]);

        $giftCard = GiftCard::create([
            'code' => 'GIFT-AUTO-LOW',
            'assigned_customer_id' => $customer->id,
            'initial_value' => 100,
            'remaining_value' => 100,
            'status' => 'active',
            'issued_by' => $user->id,
            'notes' => 'Random gift voucher.',
        ]);

        $this->actingAs($user)->post(route('finance.invoices.store'), [
            'customer_id' => $customer->id,
            'customer_display_name' => $customer->name,
            'items' => [[
                'salon_service_id' => $service->id,
                'description' => $service->name,
                'quantity' => 1,
                'unit_price' => 200,
                'discount_amount' => 0,
            ]],
        ])->assertSessionHasNoErrors();

        $invoice = TaxInvoice::query()->latest()->firstOrFail();
        $this->actingAs($user)->post(route('finance.invoices.finalize', $invoice))->assertSessionHasNoErrors();
        $invoice->refresh();

        $this->actingAs($user)->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => $invoice->total,
            'method' => InvoicePayment::METHOD_CASH,
            'paid_at' => now()->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $payments = $invoice->fresh()->payments()->get();

        $this->assertCount(1, $payments);
        $this->assertSame(InvoicePayment::METHOD_CASH, $payments->first()->method);
        $this->assertSame('100.00', $giftCard->fresh()->remaining_value);
        $this->assertSame('active', $giftCard->fresh()->status);
    }

    public function test_gift_card_payment_finds_card_from_linked_appointment_customer(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);
        $user = User::factory()->create(['role_id' => $ownerRole->id]);

        FinanceSetting::current();

        $customer = Customer::create([
            'customer_code' => 'FIN-GIFT-APPT',
            'name' => 'Appointment Gift Customer',
            'phone' => '5553332200',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Facial',
            'category' => 'Skin',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 200,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now(),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        $giftCard = GiftCard::create([
            'code' => 'GIFT-INVOICE-APPT',
            'assigned_customer_id' => $customer->id,
            'initial_value' => 250,
            'remaining_value' => 250,
            'status' => 'active',
            'issued_by' => $user->id,
        ]);

        $invoice = TaxInvoice::create([
            'invoice_number' => 'TAX-APPT-GIFT-1',
            'customer_id' => null,
            'customer_display_name' => $customer->name,
            'appointment_id' => $appointment->id,
            'status' => TaxInvoice::STATUS_FINALIZED,
            'subtotal' => 200,
            'vat_amount' => 10,
            'total' => 210,
            'issued_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 210,
            'method' => InvoicePayment::METHOD_GIFT_CARD,
            'paid_at' => now()->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $payment = $invoice->fresh()->payments()->first();

        $this->assertNotNull($payment);
        $this->assertSame(InvoicePayment::METHOD_GIFT_CARD, $payment->method);
        $this->assertEqualsWithDelta(210.0, (float) $payment->amount, 0.02);
        $this->assertSame('40.00', $giftCard->fresh()->remaining_value);
    }

    public function test_receipt_html_shows_payment_method_label(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $user = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        FinanceSetting::current();

        $customer = Customer::create([
            'customer_code' => 'FIN-C3',
            'name' => 'Cash Customer',
            'phone' => '5551114455',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Blowdry',
            'category' => 'Hair',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 100,
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('finance.invoices.store'), [
            'customer_id' => $customer->id,
            'customer_display_name' => $customer->name,
            'items' => [[
                'salon_service_id' => $service->id,
                'description' => $service->name,
                'quantity' => 1,
                'unit_price' => 100,
                'discount_amount' => 10,
            ]],
        ])->assertSessionHasNoErrors();

        $invoice = TaxInvoice::query()->latest()->firstOrFail();

        $this->actingAs($user)->post(route('finance.invoices.finalize', $invoice))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 94.5,
            'method' => 'cash',
            'paid_at' => now()->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $html = TaxReceiptPdfView::shapedHtml($invoice->fresh());

        $this->assertStringContainsString('Payment Method', $html);
        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('10.00', $html);
        $this->assertStringContainsString('Cash: 94.50', $html);
    }

    public function test_receipt_html_shows_package_membership_settlement_label(): void
    {
        FinanceSetting::current();

        $customer = Customer::create([
            'customer_code' => 'FIN-C4',
            'name' => 'Gold Package Customer',
            'phone' => '5551115566',
            'is_active' => true,
        ]);

        $cardType = MembershipCardType::create([
            'name' => 'Gold Membership',
            'slug' => 'gold-membership-receipt-test',
            'kind' => 'physical',
            'min_points' => 0,
            'is_active' => true,
        ]);

        CustomerMembershipCard::create([
            'customer_id' => $customer->id,
            'membership_card_type_id' => $cardType->id,
            'card_number' => 'GOLD-0001',
            'status' => 'active',
            'issued_at' => now(),
            'activated_at' => now(),
        ]);

        $invoice = TaxInvoice::create([
            'customer_id' => $customer->id,
            'customer_display_name' => $customer->name,
            'status' => TaxInvoice::STATUS_FINALIZED,
            'invoice_number' => 'INV-PKG-1',
            'issued_at' => now(),
            'subtotal' => 0,
            'vat_amount' => 0,
            'total' => 0,
        ]);

        $invoice->items()->create([
            'description' => 'Blowdry (package session)',
            'quantity' => 1,
            'unit_price' => 0,
            'discount_amount' => 0,
            'line_subtotal' => 0,
            'tax_rate_percent' => 5,
            'line_tax' => 0,
            'line_total' => 0,
        ]);

        $html = TaxReceiptPdfView::shapedHtml($invoice->fresh());

        $this->assertStringContainsString('Settlement Method', $html);
        $this->assertStringContainsString('Package / Membership: Gold Membership', $html);
    }

    public function test_owner_can_create_rental_income_invoice_line(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $user = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        FinanceSetting::current();

        $this->actingAs($user)
            ->post(route('finance.invoices.store'), [
                'customer_display_name' => 'Permanent Makeup Partner',
                'items' => [
                    [
                        'salon_service_id' => null,
                        'staff_profile_id' => null,
                        'revenue_category' => 'line_rental_income',
                        'cost_center' => 'permanent_makeup_rental',
                        'description' => 'Permanent Makeup Line Rental',
                        'quantity' => 1,
                        'unit_price' => 1500,
                        'discount_amount' => 0,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $invoice = TaxInvoice::query()->latest()->firstOrFail();
        $line = $invoice->items()->firstOrFail();

        $this->assertSame('line_rental_income', $line->revenue_category);
        $this->assertSame('permanent_makeup_rental', $line->cost_center);
    }

    public function test_owner_can_record_refund_adjustment_as_linked_negative_invoice(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $user = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        FinanceSetting::current();

        $customer = Customer::create([
            'customer_code' => 'FIN-REF-1',
            'name' => 'Refund Customer',
            'phone' => '5551116677',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Color Correction',
            'category' => 'Hair',
            'duration_minutes' => 120,
            'buffer_minutes' => 0,
            'price' => 500,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('finance.invoices.store'), [
                'customer_id' => $customer->id,
                'customer_display_name' => $customer->name,
                'items' => [
                    [
                        'salon_service_id' => $service->id,
                        'description' => $service->name,
                        'quantity' => 1,
                        'unit_price' => 500,
                        'discount_amount' => 0,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $invoice = TaxInvoice::query()->latest()->firstOrFail();

        $this->actingAs($user)
            ->post(route('finance.invoices.finalize', $invoice))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('finance.invoices.refund-adjustment', $invoice->fresh()), [
                'amount' => 105,
                'reason' => 'Service quality correction',
            ])
            ->assertSessionHasNoErrors();

        $adjustment = TaxInvoice::query()
            ->where('related_invoice_id', $invoice->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($adjustment);
        $this->assertSame(TaxInvoice::STATUS_FINALIZED, $adjustment->status);
        $this->assertSame('refund_adjustment', $adjustment->adjustment_type);
        $this->assertSame('Service quality correction', $adjustment->adjustment_reason);
        $this->assertLessThan(0, (float) $adjustment->total);
        $this->assertSame(FinanceStructure::DEFAULT_REVENUE_CATEGORY, $adjustment->items()->first()?->revenue_category ?? FinanceStructure::DEFAULT_REVENUE_CATEGORY);
    }
}
