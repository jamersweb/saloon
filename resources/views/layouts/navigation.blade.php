<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-auto w-44" />
                    </a>
                </div>

                <div class="hidden sm:flex sm:items-center sm:ms-10 sm:space-x-4">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
                    @if(auth()->user()->hasRole('owner', 'manager'))
                        <x-nav-link :href="route('services.index')" :active="request()->routeIs('services.*')">Services</x-nav-link>
                        <x-nav-link :href="route('staff.index')" :active="request()->routeIs('staff.*')">Staff</x-nav-link>
                        <x-nav-link :href="route('schedules.index')" :active="request()->routeIs('schedules.*')">Schedules</x-nav-link>
                    @endif
                    @if(auth()->user()->hasRole('owner', 'manager', 'staff'))
                        <x-nav-link :href="route('appointments.index')" :active="request()->routeIs('appointments.*')">Appointments</x-nav-link>
                        <x-nav-link :href="route('attendance.index')" :active="request()->routeIs('attendance.*')">Attendance</x-nav-link>
                        <x-nav-link :href="route('leave-requests.index')" :active="request()->routeIs('leave-requests.*')">Leave</x-nav-link>
                    @endif
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }} ({{ Auth::user()->role?->label ?? 'N/A' }})</div>
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">Profile</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Log Out</x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </div>
</nav>
