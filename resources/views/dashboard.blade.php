<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Vina Operations Dashboard</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <div class="p-4 bg-green-100 text-green-700 rounded">{{ session('status') }}</div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                @foreach($stats as $label => $value)
                    <div class="bg-white rounded shadow p-4">
                        <p class="text-xs text-gray-500 uppercase">{{ str_replace('_', ' ', $label) }}</p>
                        <p class="text-2xl font-semibold">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="bg-white rounded shadow p-6">
                <h3 class="font-semibold mb-4">Upcoming Appointments</h3>
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-2">Time</th>
                                <th class="py-2">Customer</th>
                                <th class="py-2">Service</th>
                                <th class="py-2">Staff</th>
                                <th class="py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($upcomingAppointments as $item)
                                <tr class="border-b">
                                    <td class="py-2">{{ $item->scheduled_start?->format('Y-m-d H:i') }}</td>
                                    <td class="py-2">{{ $item->customer_name }}</td>
                                    <td class="py-2">{{ $item->service?->name }}</td>
                                    <td class="py-2">{{ $item->staffProfile?->user?->name ?? 'Unassigned' }}</td>
                                    <td class="py-2">{{ $item->status }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-2" colspan="5">No upcoming appointments.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
