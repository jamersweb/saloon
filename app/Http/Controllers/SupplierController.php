<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/Suppliers', [
            'suppliers' => Supplier::query()->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $supplier = Supplier::create([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Audit::log($request->user()?->id, 'supplier.created', 'Supplier', $supplier->id);

        return back()->with('status', 'Supplier created.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $supplier->update([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        Audit::log($request->user()?->id, 'supplier.updated', 'Supplier', $supplier->id);

        return back()->with('status', 'Supplier updated.');
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $supplier->update(['is_active' => false]);

        Audit::log($request->user()?->id, 'supplier.deactivated', 'Supplier', $supplier->id);

        return back()->with('status', 'Supplier deactivated.');
    }
}
