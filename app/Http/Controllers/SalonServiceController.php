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
    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim($request->string('search')->toString()),
            'category' => trim($request->string('category')->toString()),
            'status' => $request->string('status')->toString() ?: 'all',
            'min_price' => $request->input('min_price'),
            'max_price' => $request->input('max_price'),
            'min_duration' => $request->input('min_duration'),
            'max_duration' => $request->input('max_duration'),
            'per_page' => (int) $request->integer('per_page', 10),
        ];

        if (! in_array($filters['status'], ['all', 'active', 'inactive'], true)) {
            $filters['status'] = 'all';
        }

        if (! in_array($filters['per_page'], [10, 25, 50, 100], true)) {
            $filters['per_page'] = 10;
        }

        $servicesQuery = SalonService::query()
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $needle = '%' . $filters['search'] . '%';
                $query->where(function ($serviceQuery) use ($needle): void {
                    $serviceQuery
                        ->where('name', 'like', $needle)
                        ->orWhere('category', 'like', $needle);
                });
            })
            ->when($filters['category'] !== '', fn ($query) => $query->where('category', $filters['category']))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when(is_numeric($filters['min_price']), fn ($query) => $query->where('price', '>=', (float) $filters['min_price']))
            ->when(is_numeric($filters['max_price']), fn ($query) => $query->where('price', '<=', (float) $filters['max_price']))
            ->when(is_numeric($filters['min_duration']), fn ($query) => $query->where('duration_minutes', '>=', (int) $filters['min_duration']))
            ->when(is_numeric($filters['max_duration']), fn ($query) => $query->where('duration_minutes', '<=', (int) $filters['max_duration']))
            ->latest();

        return Inertia::render('Services/Index', [
            'services' => $servicesQuery
                ->paginate($filters['per_page'])
                ->withQueryString(),
            'filters' => $filters,
            'categories' => SalonService::query()
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
