<?php

namespace App\Http\Controllers;

use App\Models\ExpenseEntry;
use App\Models\PurchaseOrder;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $expenses = ExpenseEntry::query()
            ->with(['purchaseOrder:id,po_number', 'createdBy:id,name'])
            ->latest('expense_date')
            ->paginate(25)
            ->through(fn (ExpenseEntry $e) => [
                'id' => $e->id,
                'category' => $e->category,
                'vendor_name' => $e->vendor_name,
                'expense_date' => $e->expense_date->toDateString(),
                'amount_subtotal' => (float) $e->amount_subtotal,
                'vat_amount' => (float) $e->vat_amount,
                'total_amount' => (float) $e->total_amount,
                'payment_status' => $e->payment_status,
                'paid_at' => optional($e->paid_at)?->toIso8601String(),
                'purchase_order' => $e->purchaseOrder ? ['id' => $e->purchaseOrder->id, 'po_number' => $e->purchaseOrder->po_number] : null,
                'notes' => $e->notes,
                'created_by_name' => $e->createdBy?->name,
            ]);

        $purchaseOrders = PurchaseOrder::query()
            ->whereIn('status', [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_RECEIVED])
            ->with('supplier:id,name')
            ->latest('order_date')
            ->limit(100)
            ->get()
            ->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'label' => $po->po_number.' · '.($po->supplier?->name ?? 'Supplier').' · '.number_format((float) $po->total_cost, 2),
            ]);

        return Inertia::render('Finance/Expenses/Index', [
            'expenses' => $expenses,
            'purchaseOrders' => $purchaseOrders,
            'categories' => self::categories(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validatedExpense($request);
        $data['created_by'] = $request->user()->id;
        $data['total_amount'] = round($data['amount_subtotal'] + $data['vat_amount'], 2);
        if ($data['payment_status'] === ExpenseEntry::STATUS_PAID && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        $expense = ExpenseEntry::query()->create($data);

        Audit::log($request->user()->id, 'finance.expense.created', 'ExpenseEntry', $expense->id, []);

        return back()->with('status', 'Expense recorded.');
    }

    public function update(Request $request, ExpenseEntry $expense): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validatedExpense($request);
        $data['total_amount'] = round($data['amount_subtotal'] + $data['vat_amount'], 2);
        if ($data['payment_status'] === ExpenseEntry::STATUS_PAID && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        $expense->update($data);

        Audit::log($request->user()->id, 'finance.expense.updated', 'ExpenseEntry', $expense->id, []);

        return back()->with('status', 'Expense updated.');
    }

    public function destroy(Request $request, ExpenseEntry $expense): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $id = $expense->id;
        $expense->delete();

        Audit::log($request->user()->id, 'finance.expense.deleted', 'ExpenseEntry', $id, []);

        return back()->with('status', 'Expense removed.');
    }

    public function markPaid(Request $request, ExpenseEntry $expense): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'paid_at' => ['nullable', 'date'],
        ]);

        $expense->update([
            'payment_status' => ExpenseEntry::STATUS_PAID,
            'paid_at' => isset($data['paid_at']) ? $data['paid_at'] : now(),
        ]);

        Audit::log($request->user()->id, 'finance.expense.marked_paid', 'ExpenseEntry', $expense->id, []);

        return back()->with('status', 'Expense marked as paid.');
    }

    /**
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'supplies' => 'Supplies',
            'rent' => 'Rent',
            'utilities' => 'Utilities',
            'marketing' => 'Marketing',
            'payroll' => 'Payroll & HR',
            'professional_fees' => 'Professional fees',
            'procurement' => 'Procurement / inventory',
            'other' => 'Other',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedExpense(Request $request): array
    {
        $cats = array_keys(self::categories());

        return $request->validate([
            'category' => ['required', Rule::in($cats)],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'expense_date' => ['required', 'date'],
            'amount_subtotal' => ['required', 'numeric', 'min:0'],
            'vat_amount' => ['required', 'numeric', 'min:0'],
            'payment_status' => ['required', Rule::in([ExpenseEntry::STATUS_UNPAID, ExpenseEntry::STATUS_PAID])],
            'paid_at' => ['nullable', 'date'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
