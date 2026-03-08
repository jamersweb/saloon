<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Staff Schedules</h2></x-slot>
    <div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if($errors->any())<div class="p-4 bg-red-100 text-red-700 rounded">{{ $errors->first() }}</div>@endif
        @if(session('status'))<div class="p-4 bg-green-100 text-green-700 rounded">{{ session('status') }}</div>@endif

        <form method="POST" action="{{ route('schedules.store') }}" class="bg-white rounded shadow p-4 grid md:grid-cols-7 gap-3">
            @csrf
            <select class="border rounded p-2" name="staff_profile_id" required>
                <option value="">Staff</option>
                @foreach($staffProfiles as $staff)<option value="{{ $staff->id }}">{{ $staff->employee_code }} - {{ $staff->user->name }}</option>@endforeach
            </select>
            <input class="border rounded p-2" type="date" name="schedule_date" required>
            <input class="border rounded p-2" type="time" name="start_time">
            <input class="border rounded p-2" type="time" name="end_time">
            <input class="border rounded p-2" type="time" name="break_start">
            <input class="border rounded p-2" type="time" name="break_end">
            <button class="bg-indigo-600 text-white rounded px-4">Save</button>
        </form>

        <div class="bg-white rounded shadow p-4 overflow-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">Date</th><th>Staff</th><th>Shift</th><th>Break</th><th>Day Off</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($schedules as $schedule)
                    <tr class="border-b">
                        <td class="py-2">{{ $schedule->schedule_date?->format('Y-m-d') }}</td>
                        <td>{{ $schedule->staffProfile->user->name }}</td>
                        <td>{{ $schedule->start_time }} - {{ $schedule->end_time }}</td>
                        <td>{{ $schedule->break_start }} - {{ $schedule->break_end }}</td>
                        <td>{{ $schedule->is_day_off ? 'Yes' : 'No' }}</td>
                        <td>
                            <form method="POST" action="{{ route('schedules.destroy', $schedule) }}">@csrf @method('DELETE')<button class="text-red-600">Delete</button></form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div></div>
</x-app-layout>
