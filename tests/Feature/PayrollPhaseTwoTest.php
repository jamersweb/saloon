<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\ExpenseEntry;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_uses_fixed_salary_and_calculates_net_amount(): void
    {
        [$manager, $staffProfile] = $this->managerAndStaff([
            'employee_code' => 'PAY-001',
            'hourly_rate' => 25,
            'monthly_salary' => 4500,
        ]);

        AttendanceLog::create([
            'staff_profile_id' => $staffProfile->id,
            'attendance_date' => '2026-07-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'late_minutes' => 0,
        ]);

        $period = PayrollPeriod::create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => PayrollPeriod::STATUS_DRAFT,
            'created_by' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->post(route('finance.payroll.generate', $period))
            ->assertSessionHasNoErrors();

        $line = PayrollLine::query()->where('payroll_period_id', $period->id)->where('staff_profile_id', $staffProfile->id)->first();

        $this->assertNotNull($line);
        $this->assertSame(PayrollLine::PAY_BASIS_FIXED, $line->pay_basis);
        $this->assertEqualsWithDelta(4500.0, (float) $line->basic_salary, 0.01);
        $this->assertEqualsWithDelta(4500.0, (float) $line->gross_amount, 0.01);
        $this->assertEqualsWithDelta(4500.0, (float) $line->net_amount, 0.01);
    }

    public function test_marking_payroll_paid_posts_expense_entries_to_finance(): void
    {
        [$manager, $staffProfile] = $this->managerAndStaff([
            'employee_code' => 'PAY-002',
            'monthly_salary' => 3000,
        ]);

        $period = PayrollPeriod::create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => PayrollPeriod::STATUS_DRAFT,
            'created_by' => $manager->id,
        ]);

        $line = PayrollLine::create([
            'payroll_period_id' => $period->id,
            'staff_profile_id' => $staffProfile->id,
            'pay_basis' => PayrollLine::PAY_BASIS_FIXED,
            'hours_worked' => 0,
            'hourly_rate' => 0,
            'basic_salary' => 3000,
            'gross_amount' => 3200,
            'bonus_amount' => 300,
            'deduction_amount' => 200,
            'net_amount' => 3000,
            'payment_method' => 'bank_transfer',
        ]);

        $this->actingAs($manager)
            ->patch(route('finance.payroll.mark-paid', $period))
            ->assertSessionHasNoErrors();

        $line->refresh();
        $period->refresh();

        $this->assertSame(PayrollPeriod::STATUS_PAID, $period->status);
        $this->assertNotNull($line->paid_at);
        $this->assertNotNull($line->finance_expense_entry_id);

        $expense = ExpenseEntry::query()->find($line->finance_expense_entry_id);
        $this->assertNotNull($expense);
        $this->assertSame('payroll', $expense->category);
        $this->assertSame('payroll_salary', $expense->expense_subcategory);
        $this->assertEqualsWithDelta(3000.0, (float) $expense->total_amount, 0.01);
        $this->assertSame(ExpenseEntry::STATUS_PAID, $expense->payment_status);
    }

    public function test_manager_can_download_payslip_pdf(): void
    {
        [$manager, $staffProfile] = $this->managerAndStaff([
            'employee_code' => 'PAY-003',
            'monthly_salary' => 2800,
        ]);

        $period = PayrollPeriod::create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => PayrollPeriod::STATUS_PAID,
            'created_by' => $manager->id,
        ]);

        $line = PayrollLine::create([
            'payroll_period_id' => $period->id,
            'staff_profile_id' => $staffProfile->id,
            'pay_basis' => PayrollLine::PAY_BASIS_FIXED,
            'hours_worked' => 0,
            'hourly_rate' => 0,
            'basic_salary' => 2800,
            'gross_amount' => 2800,
            'bonus_amount' => 0,
            'deduction_amount' => 0,
            'net_amount' => 2800,
            'payment_method' => 'bank_transfer',
            'paid_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get(route('finance.payroll.lines.payslip', ['payroll_period' => $period, 'line' => $line]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function managerAndStaff(array $profileAttributes = []): array
    {
        $managerRole = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff']);

        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $staffUser = User::factory()->create(['role_id' => $staffRole->id, 'name' => 'Payroll Staff']);

        $staffProfile = StaffProfile::create(array_merge([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-500',
            'is_active' => true,
        ], $profileAttributes));

        return [$manager, $staffProfile];
    }
}
