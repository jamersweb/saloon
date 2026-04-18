<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Staff Management</h2></x-slot>
    <div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if($errors->any())<div class="p-4 bg-red-100 text-red-700 rounded">{{ $errors->first() }}</div>@endif
        @if(session('status'))<div class="p-4 bg-green-100 text-green-700 rounded">{{ session('status') }}</div>@endif

        <form method="POST" action="{{ route('staff.store') }}" class="bg-white rounded shadow p-4 grid md:grid-cols-4 gap-3">
            @csrf
            <input class="border rounded p-2" name="name" placeholder="Full name" required>
            <input class="border rounded p-2" name="email" type="email" placeholder="Email" required>
            <input class="border rounded p-2" name="employee_code" placeholder="Employee code" required>
            <input class="border rounded p-2" name="phone" placeholder="Phone">
            <input class="border rounded p-2 md:col-span-2" name="skills" placeholder="Skills (comma separated)">
            <input class="border rounded p-2" name="password" placeholder="Optional password">
            <button class="bg-indigo-600 text-white rounded px-4">Add Staff</button>
        </form>

        <div class="bg-white rounded shadow p-4 overflow-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">Code</th><th>Name</th><th>Email</th><th>Phone</th><th>Skills</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($staffProfiles as $staff)
                    <tr class="border-b">
                        <td class="py-2">{{ $staff->employee_code }}</td>
                        <td>{{ $staff->user->name }}</td>
                        <td>{{ $staff->user->email }}</td>
                        <td>{{ $staff->phone }}</td>
                        <td>{{ implode(', ', $staff->skills ?? []) }}</td>
                        <td>{{ $staff->is_active ? 'Active' : 'Inactive' }}</td>
                        <td>
                            <form method="POST" action="{{ route('staff.deactivate', $staff) }}">@csrf<button class="text-red-600">Deactivate</button></form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div></div>
</x-app-layout>
