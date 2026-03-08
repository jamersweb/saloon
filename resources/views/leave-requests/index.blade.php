<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Leave Requests</h2></x-slot>
    <div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if($errors->any())<div class="p-4 bg-red-100 text-red-700 rounded">{{ $errors->first() }}</div>@endif
        @if(session('status'))<div class="p-4 bg-green-100 text-green-700 rounded">{{ session('status') }}</div>@endif

        <form method="POST" action="{{ route('leave-requests.store') }}" class="bg-white rounded shadow p-4 grid md:grid-cols-5 gap-3">
            @csrf
            <select class="border rounded p-2" name="staff_profile_id">
                <option value="">My profile</option>
                @foreach($staffProfiles as $staff)<option value="{{ $staff->id }}">{{ $staff->user->name }}</option>@endforeach
            </select>
            <input class="border rounded p-2" type="date" name="start_date" required>
            <input class="border rounded p-2" type="date" name="end_date" required>
            <input class="border rounded p-2" name="reason" placeholder="Reason" required>
            <button class="bg-indigo-600 text-white rounded px-4">Submit</button>
        </form>

        <div class="bg-white rounded shadow p-4 overflow-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">Staff</th><th>Date Range</th><th>Reason</th><th>Status</th><th>Review</th></tr></thead>
                <tbody>
                @foreach($leaveRequests as $leave)
                    <tr class="border-b">
                        <td class="py-2">{{ $leave->staffProfile?->user?->name }}</td>
                        <td>{{ $leave->start_date?->format('Y-m-d') }} to {{ $leave->end_date?->format('Y-m-d') }}</td>
                        <td>{{ $leave->reason }}</td>
                        <td>{{ $leave->status }}</td>
                        <td>
                            @if(auth()->user()->hasRole('owner', 'manager'))
                                <form method="POST" action="{{ route('leave-requests.review', $leave) }}" class="flex gap-2">@csrf @method('PATCH')
                                    <select name="status" class="border rounded p-1 text-xs">
                                        <option value="approved">Approve</option>
                                        <option value="rejected">Reject</option>
                                        <option value="cancelled">Cancel</option>
                                    </select>
                                    <button class="text-indigo-600 text-xs">Update</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div></div>
</x-app-layout>
