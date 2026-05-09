<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\LowStockAlert;
use App\Support\Audit;
use App\Support\InventoryAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim($request->string('search')->toString()),
            'category' => trim($request->string('category')->toString()),
            'stock_status' => $request->string('stock_status')->toString() ?: 'all',
            'min_price' => $request->input('min_price'),
            'max_price' => $request->input('max_price'),
            'per_page' => (int) $request->integer('per_page', 10),
        ];

        if (! in_array($filters['stock_status'], ['all', 'low', 'in_stock', 'active', 'inactive'], true)) {
            $filters['stock_status'] = 'all';
        }

        if (! in_array($filters['per_page'], [10, 25, 50, 100], true)) {
            $filters['per_page'] = 10;
        }

        $itemsQuery = InventoryItem::query()
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $needle = '%' . $filters['search'] . '%';
                $query->where(function ($itemQuery) use ($needle): void {
                    $itemQuery
                        ->where('sku', 'like', $needle)
                        ->orWhere('name', 'like', $needle)
                        ->orWhere('category', 'like', $needle);
                });
            })
            ->when($filters['category'] !== '', fn ($query) => $query->where('category', $filters['category']))
            ->when($filters['stock_status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['stock_status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($filters['stock_status'] === 'low', fn ($query) => $query->whereColumn('stock_quantity', '<=', 'reorder_level'))
            ->when($filters['stock_status'] === 'in_stock', fn ($query) => $query->whereColumn('stock_quantity', '>', 'reorder_level'))
            ->when(is_numeric($filters['min_price']), fn ($query) => $query->where('selling_price', '>=', (float) $filters['min_price']))
            ->when(is_numeric($filters['max_price']), fn ($query) => $query->where('selling_price', '<=', (float) $filters['max_price']))
            ->orderByDesc('is_active')
            ->orderBy('name');

        return Inertia::render('Inventory/Index', [
            'items' => $itemsQuery
                ->paginate($filters['per_page'])
                ->withQueryString(),
            'recentTransactions' => InventoryTransaction::query()
                ->with(['item:id,name,sku', 'performedBy:id,name'])
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (InventoryTransaction $transaction) => [
                    'id' => $transaction->id,
                    'item_name' => $transaction->item?->name,
                    'item_sku' => $transaction->item?->sku,
                    'type' => $transaction->type,
                    'quantity' => $transaction->quantity,
                    'reference' => $transaction->reference,
                    'notes' => $transaction->notes,
                    'performed_by' => $transaction->performedBy?->name,
                    'created_at' => $transaction->created_at,
                ]),
            'openAlerts' => LowStockAlert::query()
                ->with('item:id,name,sku')
                ->where('status', 'open')
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (LowStockAlert $alert) => [
                    'id' => $alert->id,
                    'inventory_item_id' => $alert->inventory_item_id,
                    'item_name' => $alert->item?->name,
                    'item_sku' => $alert->item?->sku,
                    'stock_quantity' => $alert->stock_quantity,
                    'reorder_level' => $alert->reorder_level,
                    'created_at' => $alert->created_at,
                ]),
            'filters' => $filters,
            'categories' => InventoryItem::query()
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validateItemPayload($request);

        $item = InventoryItem::create([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        InventoryAlerts::syncForItem($item, $request->user()?->id);

        Audit::log($request->user()?->id, 'inventory.item_created', 'InventoryItem', $item->id, $item->toArray());

        return back()->with('status', 'Inventory item created.');
    }

    public function update(Request $request, InventoryItem $item): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validateItemPayload($request, $item->id);

        $item->update([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);
        InventoryAlerts::syncForItem($item, $request->user()?->id);

        Audit::log($request->user()?->id, 'inventory.item_updated', 'InventoryItem', $item->id, $item->fresh()->toArray());

        return back()->with('status', 'Inventory item updated.');
    }

    public function adjustStock(Request $request, InventoryItem $item): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out', 'adjustment'])],
            'quantity' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $requestedQty = (int) $data['quantity'];
        if ($requestedQty === 0) {
            return back()->withErrors(['quantity' => 'Quantity cannot be zero.']);
        }

        $delta = match ($data['type']) {
            'in' => abs($requestedQty),
            'out' => -abs($requestedQty),
            default => $requestedQty,
        };

        $item->refresh();
        if (($item->stock_quantity + $delta) < 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Stock cannot go below zero.',
            ]);
        }

        DB::transaction(function () use ($request, $item, $data, $delta): void {
            $item->update([
                'stock_quantity' => $item->stock_quantity + $delta,
            ]);

            InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'type' => $data['type'],
                'quantity' => $delta,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'performed_by' => $request->user()?->id,
            ]);

            Audit::log($request->user()?->id, 'inventory.stock_adjusted', 'InventoryItem', $item->id, [
                'type' => $data['type'],
                'delta' => $delta,
                'new_stock' => $item->fresh()->stock_quantity,
            ]);
        });
        InventoryAlerts::syncForItem($item, $request->user()?->id);

        return back()->with('status', 'Stock adjusted.');
    }

    public function destroy(Request $request, InventoryItem $item): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $item->update(['is_active' => false]);

        Audit::log($request->user()?->id, 'inventory.item_deactivated', 'InventoryItem', $item->id);

        return back()->with('status', 'Inventory item deactivated.');
    }

    public function resolveAlert(Request $request, LowStockAlert $alert): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $alert->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $request->user()?->id,
        ]);

        Audit::log($request->user()?->id, 'inventory.alert_resolved', 'LowStockAlert', $alert->id);

        return back()->with('status', 'Low stock alert resolved.');
    }

    public function scanAlerts(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        InventoryAlerts::scanAll($request->user()?->id);

        Audit::log($request->user()?->id, 'inventory.alerts_scanned', 'LowStockAlert');

        return back()->with('status', 'Low stock alerts refreshed.');
    }

    private function validateItemPayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'sku' => ['required', 'string', 'max:100', Rule::unique('inventory_items', 'sku')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:30'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
