<?php

namespace Tests\Feature;

use App\Models\ExpenseEntry;
use App\Models\PettyCashClosing;
use App\Models\PettyCashEntry;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseEntryPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_record_staff_welfare_expense_with_receipt(): void
    {
        Storage::fake('public');

        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        $staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'name' => 'Tea Staff',
        ]);

        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EXP-STAFF-1',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('finance.expenses.store'), [
                'category' => 'staff_welfare',
                'expense_type' => 'staff_welfare',
                'expense_subcategory' => 'staff_drinks',
                'vendor_name' => 'Pantry Market',
                'expense_date' => now()->toDateString(),
                'amount_subtotal' => 25,
                'vat_amount' => 0,
                'payment_status' => ExpenseEntry::STATUS_PAID,
                'payment_method' => 'cash',
                'receipt_number' => 'R-100',
                'receipt_image' => UploadedFile::fake()->image('receipt.jpg'),
                'staff_profile_id' => $staffProfile->id,
                'notes' => 'Tea and coffee for staff.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $expense = ExpenseEntry::query()->latest()->first();
        $this->assertNotNull($expense);
        $this->assertSame('staff_welfare', $expense->category);
        $this->assertSame('staff_welfare', $expense->expense_type);
        $this->assertSame('staff_drinks', $expense->expense_subcategory);
        $this->assertSame(ExpenseEntry::APPROVAL_PENDING, $expense->approval_status);
        $this->assertSame('cash', $expense->payment_method);
        $this->assertSame($staffProfile->id, $expense->staff_profile_id);
        $this->assertSame('R-100', $expense->receipt_number);
        $this->assertNotNull($expense->receipt_image_path);
        Storage::disk('public')->assertExists($expense->receipt_image_path);
    }

    public function test_owner_can_approve_pending_expense(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        $expense = ExpenseEntry::create([
            'category' => 'staff_welfare',
            'expense_type' => 'staff_welfare',
            'expense_subcategory' => 'staff_meal',
            'expense_date' => now()->toDateString(),
            'amount_subtotal' => 50,
            'vat_amount' => 0,
            'total_amount' => 50,
            'payment_status' => ExpenseEntry::STATUS_PAID,
            'payment_method' => 'cash',
            'approval_status' => ExpenseEntry::APPROVAL_PENDING,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('finance.expenses.approval.update', $expense), [
                'approval_status' => ExpenseEntry::APPROVAL_APPROVED,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $expense->refresh();
        $this->assertSame(ExpenseEntry::APPROVAL_APPROVED, $expense->approval_status);
        $this->assertSame($owner->id, $expense->approved_by);
        $this->assertNotNull($expense->approved_at);
    }

    public function test_petty_cash_issue_and_approved_expense_update_balance(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        $staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'name' => 'Cash Custodian',
        ]);

        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'PETTY-01',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('finance.expenses.petty-cash.issue'), [
                'staff_profile_id' => $staffProfile->id,
                'amount' => 200,
                'transaction_date' => now()->toDateString(),
                'notes' => 'Front desk float',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $issue = PettyCashEntry::query()->latest()->first();
        $this->assertNotNull($issue);
        $this->assertSame(PettyCashEntry::TYPE_ISSUE, $issue->transaction_type);
        $this->assertSame(PettyCashEntry::DIRECTION_IN, $issue->direction);

        $expense = ExpenseEntry::create([
            'category' => 'petty_cash',
            'expense_type' => 'petty_cash',
            'expense_subcategory' => 'office_supplies',
            'vendor_name' => 'Stationery Store',
            'expense_date' => now()->toDateString(),
            'amount_subtotal' => 40,
            'vat_amount' => 0,
            'total_amount' => 40,
            'payment_status' => ExpenseEntry::STATUS_PAID,
            'payment_method' => 'cash',
            'approval_status' => ExpenseEntry::APPROVAL_PENDING,
            'staff_profile_id' => $staffProfile->id,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('finance.expenses.approval.update', $expense), [
                'approval_status' => ExpenseEntry::APPROVAL_APPROVED,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $expense->refresh();
        $this->assertSame(ExpenseEntry::APPROVAL_APPROVED, $expense->approval_status);

        $expenseEntry = PettyCashEntry::query()
            ->where('expense_entry_id', $expense->id)
            ->first();

        $this->assertNotNull($expenseEntry);
        $this->assertSame(PettyCashEntry::TYPE_EXPENSE, $expenseEntry->transaction_type);
        $this->assertSame(PettyCashEntry::DIRECTION_OUT, $expenseEntry->direction);

        $balance = PettyCashEntry::query()
            ->where('staff_profile_id', $staffProfile->id)
            ->get()
            ->sum(fn (PettyCashEntry $entry) => $entry->direction === PettyCashEntry::DIRECTION_IN ? (float) $entry->amount : -(float) $entry->amount);

        $this->assertEqualsWithDelta(160.0, $balance, 0.01);
    }

    public function test_petty_cash_expense_cannot_be_approved_when_balance_is_insufficient(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        $staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'name' => 'Low Balance Custodian',
        ]);

        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'PETTY-02',
            'is_active' => true,
        ]);

        PettyCashEntry::create([
            'staff_profile_id' => $staffProfile->id,
            'transaction_type' => PettyCashEntry::TYPE_ISSUE,
            'direction' => PettyCashEntry::DIRECTION_IN,
            'amount' => 20,
            'transaction_date' => now()->toDateString(),
            'created_by' => $owner->id,
        ]);

        $expense = ExpenseEntry::create([
            'category' => 'petty_cash',
            'expense_type' => 'petty_cash',
            'expense_subcategory' => 'small_tools',
            'expense_date' => now()->toDateString(),
            'amount_subtotal' => 50,
            'vat_amount' => 0,
            'total_amount' => 50,
            'payment_status' => ExpenseEntry::STATUS_PAID,
            'payment_method' => 'cash',
            'approval_status' => ExpenseEntry::APPROVAL_PENDING,
            'staff_profile_id' => $staffProfile->id,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->from(route('finance.expenses.index'))
            ->patch(route('finance.expenses.approval.update', $expense), [
                'approval_status' => ExpenseEntry::APPROVAL_APPROVED,
            ])
            ->assertRedirect(route('finance.expenses.index'))
            ->assertSessionHasErrors('approval_status');

        $expense->refresh();
        $this->assertSame(ExpenseEntry::APPROVAL_PENDING, $expense->approval_status);
        $this->assertDatabaseMissing('petty_cash_entries', [
            'expense_entry_id' => $expense->id,
        ]);
    }

    public function test_petty_cash_print_report_renders_settlement_data(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        PettyCashEntry::create([
            'transaction_type' => PettyCashEntry::TYPE_ISSUE,
            'direction' => PettyCashEntry::DIRECTION_IN,
            'amount' => 75,
            'transaction_date' => now()->toDateString(),
            'notes' => 'Opening drawer cash',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->get(route('finance.expenses.petty-cash.print', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Petty Cash Closing Report', false)
            ->assertSee('Opening drawer cash', false);
    }

    public function test_closing_creates_sign_off_and_variance_adjustment(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
            'name' => 'Closer User',
        ]);

        PettyCashEntry::create([
            'transaction_type' => PettyCashEntry::TYPE_ISSUE,
            'direction' => PettyCashEntry::DIRECTION_IN,
            'amount' => 100,
            'transaction_date' => now()->toDateString(),
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->post(route('finance.expenses.petty-cash.close'), [
                'closing_date' => now()->toDateString(),
                'counted_closing_balance' => 90,
                'signed_off_name' => 'Closer User',
                'notes' => 'Drawer short by 10',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $closing = PettyCashClosing::query()->latest()->first();
        $this->assertNotNull($closing);
        $this->assertSame('Closer User', $closing->signed_off_name);
        $this->assertEqualsWithDelta(-10.0, (float) $closing->variance_amount, 0.01);
        $this->assertNotNull($closing->variance_entry_id);

        $varianceEntry = PettyCashEntry::query()->find($closing->variance_entry_id);
        $this->assertNotNull($varianceEntry);
        $this->assertSame(PettyCashEntry::TYPE_ADJUSTMENT, $varianceEntry->transaction_type);
        $this->assertSame(PettyCashEntry::DIRECTION_OUT, $varianceEntry->direction);
    }

    public function test_closed_petty_cash_date_blocks_new_approval(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        PettyCashClosing::create([
            'closing_date' => now()->toDateString(),
            'opening_balance' => 0,
            'issued_total' => 0,
            'spent_total' => 0,
            'expected_closing_balance' => 0,
            'counted_closing_balance' => 0,
            'variance_amount' => 0,
            'signed_off_name' => 'Owner',
            'closed_by' => $owner->id,
            'closed_at' => now(),
        ]);

        $expense = ExpenseEntry::create([
            'category' => 'petty_cash',
            'expense_type' => 'petty_cash',
            'expense_subcategory' => 'misc_cash',
            'expense_date' => now()->toDateString(),
            'amount_subtotal' => 10,
            'vat_amount' => 0,
            'total_amount' => 10,
            'payment_status' => ExpenseEntry::STATUS_PAID,
            'payment_method' => 'cash',
            'approval_status' => ExpenseEntry::APPROVAL_PENDING,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->from(route('finance.expenses.index'))
            ->patch(route('finance.expenses.approval.update', $expense), [
                'approval_status' => ExpenseEntry::APPROVAL_APPROVED,
            ])
            ->assertRedirect(route('finance.expenses.index'))
            ->assertSessionHasErrors('expense_date');
    }

    public function test_editing_approved_petty_cash_expense_removes_old_entry_and_returns_to_pending(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        PettyCashEntry::create([
            'transaction_type' => PettyCashEntry::TYPE_ISSUE,
            'direction' => PettyCashEntry::DIRECTION_IN,
            'amount' => 200,
            'transaction_date' => now()->toDateString(),
            'created_by' => $owner->id,
        ]);

        $expense = ExpenseEntry::create([
            'category' => 'petty_cash',
            'expense_type' => 'petty_cash',
            'expense_subcategory' => 'office_supplies',
            'expense_date' => now()->toDateString(),
            'amount_subtotal' => 40,
            'vat_amount' => 0,
            'total_amount' => 40,
            'payment_status' => ExpenseEntry::STATUS_PAID,
            'payment_method' => 'cash',
            'approval_status' => ExpenseEntry::APPROVAL_APPROVED,
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'created_by' => $owner->id,
        ]);

        PettyCashEntry::create([
            'expense_entry_id' => $expense->id,
            'transaction_type' => PettyCashEntry::TYPE_EXPENSE,
            'direction' => PettyCashEntry::DIRECTION_OUT,
            'amount' => 40,
            'transaction_date' => now()->toDateString(),
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->put(route('finance.expenses.update', $expense), [
                'category' => 'petty_cash',
                'expense_type' => 'petty_cash',
                'expense_subcategory' => 'small_tools',
                'vendor_name' => 'Updated Vendor',
                'expense_date' => now()->toDateString(),
                'amount_subtotal' => 30,
                'vat_amount' => 0,
                'payment_status' => ExpenseEntry::STATUS_PAID,
                'payment_method' => 'cash',
                'staff_profile_id' => null,
                'purchase_order_id' => null,
                'notes' => 'Updated note',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $expense->refresh();
        $this->assertSame(ExpenseEntry::APPROVAL_PENDING, $expense->approval_status);
        $this->assertSame('Updated Vendor', $expense->vendor_name);
        $this->assertDatabaseMissing('petty_cash_entries', [
            'expense_entry_id' => $expense->id,
            'transaction_type' => PettyCashEntry::TYPE_EXPENSE,
        ]);
    }
}
