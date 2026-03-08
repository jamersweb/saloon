<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment - Vina Management System</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 min-h-screen">
    <div class="max-w-3xl mx-auto p-6">
        <div class="bg-white rounded shadow p-6 space-y-4">
            <h1 class="text-2xl font-semibold">Book Your Appointment</h1>
            <p class="text-sm text-gray-500">Public booking flow (status set to pending until staff confirmation).</p>

            @if($errors->any())<div class="p-4 bg-red-100 text-red-700 rounded">{{ $errors->first() }}</div>@endif
            @if(session('status'))<div class="p-4 bg-green-100 text-green-700 rounded">{{ session('status') }}</div>@endif

            <form method="POST" action="{{ route('public.booking.store') }}" class="grid md:grid-cols-2 gap-3">
                @csrf
                <input class="border rounded p-2" name="customer_name" placeholder="Your name" required>
                <input class="border rounded p-2" name="customer_phone" placeholder="Phone" required>
                <input class="border rounded p-2 md:col-span-2" name="customer_email" type="email" placeholder="Email">
                <select class="border rounded p-2" name="service_id" required>
                    <option value="">Select service</option>
                    @foreach($services as $service)<option value="{{ $service->id }}">{{ $service->name }} ({{ $service->duration_minutes }} min)</option>@endforeach
                </select>
                <select class="border rounded p-2" name="staff_profile_id">
                    <option value="">Any available staff</option>
                    @foreach($staffProfiles as $staff)<option value="{{ $staff->id }}">{{ $staff->user->name }}</option>@endforeach
                </select>
                <input class="border rounded p-2 md:col-span-2" type="datetime-local" name="scheduled_start" required>
                <textarea class="border rounded p-2 md:col-span-2" name="notes" placeholder="Notes"></textarea>
                <button class="bg-indigo-600 text-white rounded px-4 py-2 md:col-span-2">Submit Booking</button>
            </form>

            <div class="text-sm">
                <a href="{{ route('login') }}" class="text-indigo-600">Staff login</a>
            </div>
        </div>
    </div>
</body>
</html>
