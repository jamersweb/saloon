<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Attendance</h2></x-slot>
    <div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if($errors->any())<div class="p-4 bg-red-100 text-red-700 rounded">{{ $errors->first() }}</div>@endif
        @if(session('status'))<div class="p-4 bg-green-100 text-green-700 rounded">{{ session('status') }}</div>@endif

        <div class="bg-white rounded shadow p-4 grid md:grid-cols-3 gap-3">
            <form method="POST" action="{{ route('attendance.clock-in') }}" class="space-y-2">
                @csrf
                <select class="border rounded p-2 w-full" name="staff_profile_id">
                    <option value="">My profile</option>
                    @foreach($staffProfiles as $staff)<option value="{{ $staff->id }}">{{ $staff->user->name }}</option>@endforeach
                </select>
                <button class="bg-indigo-600 text-white rounded px-4 py-2 w-full">Clock In</button>
            </form>
            <form method="POST" action="{{ route('attendance.clock-out') }}" class="space-y-2">
                @csrf
                <select class="border rounded p-2 w-full" name="staff_profile_id">
                    <option value="">My profile</option>
                    @foreach($staffProfiles as $staff)<option value="{{ $staff->id }}">{{ $staff->user->name }}</option>@endforeach
                </select>
                <button class="bg-gray-700 text-white rounded px-4 py-2 w-full">Clock Out</button>
            </form>
        </div>

        <div class="bg-white rounded shadow p-4 overflow-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">Date</th><th>Staff</th><th>In</th><th>Out</th><th>Late (min)</th></tr></thead>
                <tbody>
                @foreach($logs as $log)
                    <tr class="border-b">
                        <td class="py-2">{{ $log->attendance_date?->format('Y-m-d') }}</td>
                        <td>{{ $log->staffProfile?->user?->name }}</td>
                        <td>{{ $log->clock_in }}</td>
                        <td>{{ $log->clock_out }}</td>
                        <td>{{ $log->late_minutes }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div></div>
</x-app-layout>
