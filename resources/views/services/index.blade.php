<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Services</h2></x-slot>
    <div class="py-8"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if($errors->any())<div class="p-4 bg-red-100 text-red-700 rounded">{{ $errors->first() }}</div>@endif
        @if(session('status'))<div class="p-4 bg-green-100 text-green-700 rounded">{{ session('status') }}</div>@endif

        <form method="POST" action="{{ route('services.store') }}" class="bg-white rounded shadow p-4 grid md:grid-cols-6 gap-3">
            @csrf
            <input class="border rounded p-2" name="name" placeholder="Service name" required>
            <input class="border rounded p-2" name="category" placeholder="Category">
            <input class="border rounded p-2" name="duration_minutes" type="number" min="5" placeholder="Duration (min)" required>
            <input class="border rounded p-2" name="buffer_minutes" type="number" min="0" placeholder="Buffer">
            <input class="border rounded p-2" name="price" type="number" step="0.01" min="0" placeholder="Price" required>
            <button class="bg-indigo-600 text-white rounded px-4">Add Service</button>
        </form>

        <div class="bg-white rounded shadow p-4 overflow-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left border-b"><th class="py-2">Name</th><th>Category</th><th>Duration</th><th>Price</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($services as $service)
                    <tr class="border-b">
                        <td class="py-2">{{ $service->name }}</td><td>{{ $service->category }}</td><td>{{ $service->duration_minutes }}m</td><td>{{ $service->price }}</td><td>{{ $service->is_active ? 'Active' : 'Inactive' }}</td>
                        <td>
                            <form method="POST" action="{{ route('services.destroy', $service) }}">@csrf @method('DELETE')<button class="text-red-600">Deactivate</button></form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div></div>
</x-app-layout>
