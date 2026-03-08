<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Appointments</h2></x-slot>
    <div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if($errors->any())<div class="p-4 bg-red-100 text-red-700 rounded">{{ $errors->first() }}</div>@endif
        @if(session('status'))<div class="p-4 bg-green-100 text-green-700 rounded">{{ session('status') }}</div>@endif

        <form method="POST" action="{{ route('appointments.store') }}" class="bg-white rounded shadow p-4 grid md:grid-cols-4 gap-3">
            @csrf
            <input class="border rounded p-2" name="customer_name" placeholder="Customer name" required>
            <input class="border rounded p-2" name="customer_phone" placeholder="Phone" required>
            <input class="border rounded p-2" name="customer_email" type="email" placeholder="Email">
            <select class="border rounded p-2" name="service_id" required>
                <option value="">Service</option>
                @foreach($services as $service)<option value="{{ $service->id }}">{{ $service->name }} ({{ $service->duration_minutes }}m)</option>@endforeach
            </select>
            <select class="border rounded p-2" name="staff_profile_id">
                <option value="">Unassigned Staff</option>
                @foreach($staffProfiles as $staff)<option value="{{ $staff->id }}">{{ $staff->user->name }}</option>@endforeach
            </select>
            <input class="border rounded p-2" type="datetime-local" name="scheduled_start" required>
            <select class="border rounded p-2" name="status">
                <option value="confirmed">confirmed</option><option value="pending">pending</option>
            </select>
            <button class="bg-indigo-600 text-white rounded px-4">Create Appointment</button>
        </form>

        <div class="bg-white rounded shadow p-4 overflow-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">Time</th><th>Customer</th><th>Service</th><th>Staff</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($appointments as $appointment)
                    <tr class="border-b">
                        <td class="py-2">{{ $appointment->scheduled_start?->format('Y-m-d H:i') }}</td>
                        <td>{{ $appointment->customer_name }}<br><span class="text-xs text-gray-500">{{ $appointment->customer_phone }}</span></td>
                        <td>{{ $appointment->service?->name }}</td>
                        <td>{{ $appointment->staffProfile?->user?->name ?? 'Unassigned' }}</td>
                        <td>{{ $appointment->status }}</td>
                        <td>
                            <form method="POST" action="{{ route('appointments.destroy', $appointment) }}">@csrf @method('DELETE')<button class="text-red-600">Cancel</button></form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div></div>
</x-app-layout>
