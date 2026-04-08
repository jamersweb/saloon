<?php

namespace Tests\Feature;

use App\Mail\TaxInvoiceReceiptMail;
use App\Models\Customer;
use App\Models\FinanceSetting;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\TaxInvoice;
use App\Models\User;
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
                        'description' => $service->name,
                        'quantity' => 1,
                        'unit_price' => 550,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invoice = TaxInvoice::query()->latest()->first();
        $this->assertNotNull($invoice);
        $this->assertSame(TaxInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertEqualsWithDelta(550.0, (float) $invoice->subtotal, 0.02);
        $this->assertEqualsWithDelta(27.5, (float) $invoice->vat_amount, 0.02);
        $this->assertEqualsWithDelta(577.5, (float) $invoice->total, 0.02);

        $this->actingAs($user)
            ->post(route('finance.invoices.finalize', $invoice))
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(TaxInvoice::STATUS_FINALIZED, $invoice->status);
        $this->assertNotNull($invoice->invoice_number);

        $this->actingAs($user)
            ->post(route('finance.invoices.payments.store', $invoice), [
                'amount' => 577.5,
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
}
