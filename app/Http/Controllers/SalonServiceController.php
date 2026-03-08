<?php

namespace App\Http\Controllers;

use App\Models\SalonService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalonServiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Services/Index', [
            'services' => SalonService::query()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'repeat_after_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $service = SalonService::create([
            ...$data,
            'buffer_minutes' => $data['buffer_minutes'] ?? 0,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Audit::log($request->user()->id, 'service.created', 'SalonService', $service->id, $service->toArray());

        return back()->with('status', 'Service created.');
    }

    public function update(Request $request, SalonService $service): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'repeat_after_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $service->update([
            ...$data,
            'buffer_minutes' => $data['buffer_minutes'] ?? 0,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        Audit::log($request->user()->id, 'service.updated', 'SalonService', $service->id, $service->fresh()->toArray());

        return back()->with('status', 'Service updated.');
    }

    public function destroy(Request $request, SalonService $service): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $service->update(['is_active' => false]);

        Audit::log($request->user()->id, 'service.deactivated', 'SalonService', $service->id);

        return back()->with('status', 'Service deactivated.');
    }
}
