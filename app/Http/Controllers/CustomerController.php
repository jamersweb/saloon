<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Customer;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $query = trim($request->string('q')->toString());
        $selectedId = $request->integer('customer_id');

        $customers = Customer::query()
            ->when($query, function ($builder) use ($query) {
                $builder->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                        ->orWhere('phone', 'like', "%{$query}%")
                        ->orWhere('customer_code', 'like', "%{$query}%");
                });
            })
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $selectedCustomer = $selectedId
            ? $customers->firstWhere('id', $selectedId) ?? Customer::find($selectedId)
            : $customers->first();

        $history = collect();

        if ($selectedCustomer) {
            $history = Appointment::query()
                ->with(['service:id,name', 'staffProfile.user:id,name'])
                ->where('customer_id', $selectedCustomer->id)
                ->orderByDesc('scheduled_start')
                ->limit(30)
                ->get();
        }

        return Inertia::render('Customers/Index', [
            'filters' => [
                'q' => $query,
            ],
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'customer_code' => $customer->customer_code,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'allergies' => $customer->allergies,
                'notes' => $customer->notes,
                'acquisition_source' => $customer->acquisition_source,
                'is_active' => $customer->is_active,
                'birthday' => $customer->birthday?->format('Y-m-d'),
            ]),
            'selectedCustomer' => $selectedCustomer ? [
                'id' => $selectedCustomer->id,
                'customer_code' => $selectedCustomer->customer_code,
                'name' => $selectedCustomer->name,
                'phone' => $selectedCustomer->phone,
                'email' => $selectedCustomer->email,
                'allergies' => $selectedCustomer->allergies,
                'notes' => $selectedCustomer->notes,
                'acquisition_source' => $selectedCustomer->acquisition_source,
                'birthday' => $selectedCustomer->birthday?->format('Y-m-d'),
            ] : null,
            'history' => $history->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'scheduled_start' => $appointment->scheduled_start,
                'status' => $appointment->status,
                'service_name' => $appointment->service?->name,
                'staff_name' => $appointment->staffProfile?->user?->name,
                'notes' => $appointment->notes,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'birthday' => ['nullable', 'date'],
            'allergies' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'acquisition_source' => ['nullable', 'string', 'max:255'],
        ]);

        $customer = Customer::create([
            ...$data,
            'customer_code' => 'CUST-' . now()->format('Ymd') . '-' . random_int(1000, 9999),
            'is_active' => true,
        ]);

        Audit::log($request->user()?->id, 'customer.created', 'Customer', $customer->id);

        return back()->with('status', 'Customer created.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'birthday' => ['nullable', 'date'],
            'allergies' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'acquisition_source' => ['nullable', 'string', 'max:255'],
        ]);

        $customer->update($data);

        Audit::log($request->user()?->id, 'customer.updated', 'Customer', $customer->id);

        return back()->with('status', 'Customer updated.');
    }
}
