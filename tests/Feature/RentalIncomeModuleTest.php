<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FinanceSetting;
use App\Models\RentalAgreement;
use App\Models\Role;
use App\Models\TaxInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalIncomeModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_rental_agreement(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
        ]);

        $user = User::factory()->create([
            'role_id' => $managerRole->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'RENT-C-1',
            'name' => 'PMU Partner',
            'phone' => '5551117000',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('finance.rentals.store'), [
                'customer_id' => $customer->id,
                'partner_name' => 'PMU Partner',
                'agreement_type' => 'line',
                'cost_center' => 'permanent_makeup_rental',
                'rental_model' => 'hybrid',
                'fixed_rent_amount' => 1200,
                'commission_percent' => 15,
                'start_date' => '2026-07-01',
                'notes' => 'Permanent makeup room rental.',
            ])
            ->assertSessionHasNoErrors();

        $agreement = RentalAgreement::query()->first();

        $this->assertNotNull($agreement);
        $this->assertSame('PMU Partner', $agreement->partner_name);
        $this->assertSame('line', $agreement->agreement_type);
        $this->assertSame('hybrid', $agreement->rental_model);
        $this->assertSame('permanent_makeup_rental', $agreement->cost_center);
    }

    public function test_manager_can_post_rental_settlement_to_finance(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
        ]);

        $user = User::factory()->create([
            'role_id' => $managerRole->id,
        ]);

        FinanceSetting::current();

        $agreement = RentalAgreement::create([
            'partner_name' => 'Chair Specialist',
            'agreement_type' => 'chair',
            'cost_center' => 'general_salon',
            'rental_model' => 'hybrid',
            'fixed_rent_amount' => 800,
            'commission_percent' => 10,
            'start_date' => '2026-07-01',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('finance.rentals.settle', $agreement), [
                'settlement_date' => '2026-07-31',
                'gross_sales_amount' => 5000,
                'fixed_rent_amount' => 800,
                'notes' => 'July settlement',
            ])
            ->assertSessionHasNoErrors();

        $agreement->refresh();
        $settlement = $agreement->settlements()->first();

        $this->assertNotNull($settlement);
        $this->assertEqualsWithDelta(500.0, (float) $settlement->commission_amount, 0.01);
        $this->assertEqualsWithDelta(1300.0, (float) $settlement->total_amount, 0.01);

        $invoice = TaxInvoice::query()->find($settlement->tax_invoice_id);
        $this->assertNotNull($invoice);
        $this->assertSame(TaxInvoice::STATUS_FINALIZED, $invoice->status);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('chair_rental_income', $invoice->items()->orderBy('id')->first()->revenue_category);
        $this->assertSame('commission_income', $invoice->items()->orderByDesc('id')->first()->revenue_category);
    }
}
