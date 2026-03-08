<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Support\Audit;
use App\Support\InventoryAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/PurchaseOrders', [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'items' => InventoryItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'cost_price']),
            'purchaseOrders' => PurchaseOrder::query()
                ->with(['supplier:id,name', 'items.item:id,name,sku', 'createdBy:id,name', 'approvedBy:id,name', 'receivedBy:id,name'])
                ->latest()
                ->limit(80)
                ->get()
                ->map(fn (PurchaseOrder $po) => [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'supplier_id' => $po->supplier_id,
                    'supplier_name' => $po->supplier?->name,
                    'status' => $po->status,
                    'order_date' => $po->order_date,
                    'expected_date' => $po->expected_date,
                    'total_cost' => $po->total_cost,
                    'notes' => $po->notes,
                    'created_by' => $po->createdBy?->name,
                    'approved_by' => $po->approvedBy?->name,
                    'received_by' => $po->receivedBy?->name,
                    'items' => $po->items->map(fn (PurchaseOrderItem $row) => [
                        'id' => $row->id,
                        'inventory_item_id' => $row->inventory_item_id,
                        'item_name' => $row->item?->name,
                        'item_sku' => $row->item?->sku,
                        'quantity_ordered' => $row->quantity_ordered,
                        'quantity_received' => $row->quantity_received,
                        'unit_cost' => $row->unit_cost,
                        'line_total' => $row->line_total,
                    ]),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $po = PurchaseOrder::create([
                'po_number' => 'PO-' . now()->format('Ymd-His') . '-' . random_int(100, 999),
                'supplier_id' => $data['supplier_id'],
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $total = 0;
            foreach ($data['items'] as $entry) {
                $lineTotal = ((int) $entry['quantity_ordered']) * ((float) $entry['unit_cost']);
                $total += $lineTotal;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'inventory_item_id' => (int) $entry['inventory_item_id'],
                    'quantity_ordered' => (int) $entry['quantity_ordered'],
                    'unit_cost' => (float) $entry['unit_cost'],
                    'line_total' => $lineTotal,
                ]);
            }

            $po->update(['total_cost' => $total]);

            Audit::log($request->user()?->id, 'purchase_order.created', 'PurchaseOrder', $po->id, ['total_cost' => $total]);
        });

        return back()->with('status', 'Purchase order created.');
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft purchase orders can be edited.',
            ]);
        }

        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $purchaseOrder, $data): void {
            $purchaseOrder->items()->delete();

            $total = 0;
            foreach ($data['items'] as $entry) {
                $lineTotal = ((int) $entry['quantity_ordered']) * ((float) $entry['unit_cost']);
                $total += $lineTotal;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'inventory_item_id' => (int) $entry['inventory_item_id'],
                    'quantity_ordered' => (int) $entry['quantity_ordered'],
                    'unit_cost' => (float) $entry['unit_cost'],
                    'line_total' => $lineTotal,
                ]);
            }

            $purchaseOrder->update([
                'supplier_id' => $data['supplier_id'],
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'total_cost' => $total,
            ]);

            Audit::log($request->user()?->id, 'purchase_order.updated', 'PurchaseOrder', $purchaseOrder->id, ['total_cost' => $total]);
        });

        return back()->with('status', 'Purchase order updated.');
    }

    public function transition(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'status' => ['required', Rule::in([PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELLED])],
        ]);

        $next = $data['status'];

        if ($next === PurchaseOrder::STATUS_APPROVED) {
            if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Only draft purchase orders can be approved.']);
            }

            $purchaseOrder->update([
                'status' => PurchaseOrder::STATUS_APPROVED,
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
            ]);

            Audit::log($request->user()?->id, 'purchase_order.approved', 'PurchaseOrder', $purchaseOrder->id);

            return back()->with('status', 'Purchase order approved.');
        }

        if ($next === PurchaseOrder::STATUS_CANCELLED) {
            if ($purchaseOrder->status === PurchaseOrder::STATUS_RECEIVED) {
                throw ValidationException::withMessages(['status' => 'Received purchase orders cannot be cancelled.']);
            }

            $purchaseOrder->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
            Audit::log($request->user()?->id, 'purchase_order.cancelled', 'PurchaseOrder', $purchaseOrder->id);

            return back()->with('status', 'Purchase order cancelled.');
        }

        if ($purchaseOrder->status !== PurchaseOrder::STATUS_APPROVED) {
            throw ValidationException::withMessages(['status' => 'Only approved purchase orders can be received.']);
        }

        DB::transaction(function () use ($request, $purchaseOrder): void {
            $purchaseOrder->loadMissing('items');

            foreach ($purchaseOrder->items as $row) {
                $item = InventoryItem::findOrFail($row->inventory_item_id);

                $item->update([
                    'stock_quantity' => $item->stock_quantity + $row->quantity_ordered,
                    'cost_price' => $row->unit_cost,
                ]);

                $row->update(['quantity_received' => $row->quantity_ordered]);

                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'type' => 'in',
                    'quantity' => $row->quantity_ordered,
                    'reference' => $purchaseOrder->po_number,
                    'notes' => 'Stock received from purchase order',
                    'performed_by' => $request->user()?->id,
                ]);

                InventoryAlerts::syncForItem($item, $request->user()?->id);
            }

            $purchaseOrder->update([
                'status' => PurchaseOrder::STATUS_RECEIVED,
                'received_by' => $request->user()?->id,
                'received_at' => now(),
            ]);

            Audit::log($request->user()?->id, 'purchase_order.received', 'PurchaseOrder', $purchaseOrder->id);
        });

        return back()->with('status', 'Purchase order received and stock updated.');
    }
}
