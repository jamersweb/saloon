<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ExpenseEntry;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\FinanceStructure;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_dashboard_shows_assigned_upcoming_appointments_beyond_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);

        $staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'name' => 'Assigned Staff',
        ]);

        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STAFF-DASH-01',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Dashboard Service',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 100,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_name' => 'Dashboard Client',
            'customer_phone' => '5551234567',
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'scheduled_start' => Carbon::parse('2026-07-02 14:00:00'),
            'scheduled_end' => Carbon::parse('2026-07-02 14:45:00'),
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $this->actingAs($staffUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('upcomingAppointments.0.id', $appointment->id)
                ->where('upcomingAppointments.0.customer_name', 'Dashboard Client')
                ->where('upcomingAppointments.0.staff_name', 'Assigned Staff')
            );
    }

    public function test_finance_dashboard_exposes_default_cost_center_watch_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00'));

        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
            'permissions' => Permissions::defaultsForRole('owner'),
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        $invoice = TaxInvoice::create([
            'customer_display_name' => 'Default Center Client',
            'status' => TaxInvoice::STATUS_FINALIZED,
            'subtotal' => 100,
            'vat_amount' => 5,
            'total' => 105,
            'issued_at' => now(),
            'created_by' => $owner->id,
        ]);

        TaxInvoiceItem::create([
            'tax_invoice_id' => $invoice->id,
            'revenue_category' => 'service_income',
            'cost_center' => FinanceStructure::DEFAULT_COST_CENTER,
            'description' => 'Legacy invoice line',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'line_subtotal' => 100,
            'tax_rate_percent' => 5,
            'line_tax' => 5,
            'line_total' => 105,
        ]);

        ExpenseEntry::create([
            'category' => 'miscellaneous',
            'cost_center' => FinanceStructure::DEFAULT_COST_CENTER,
            'expense_type' => 'operational',
            'expense_subcategory' => 'other_ops',
            'vendor_name' => 'Legacy vendor',
            'expense_date' => now()->toDateString(),
            'amount_subtotal' => 50,
            'vat_amount' => 2.5,
            'total_amount' => 52.5,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'approval_status' => 'pending',
            'notes' => 'Legacy default center expense',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->get(route('finance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Dashboard')
                ->where('data_quality.default_cost_center_invoice_lines.count', 1)
                ->where('data_quality.default_cost_center_invoice_lines.total', 105)
                ->where('data_quality.default_cost_center_expenses.count', 1)
                ->where('data_quality.default_cost_center_expenses.total', 52.5)
            );
    }
}
