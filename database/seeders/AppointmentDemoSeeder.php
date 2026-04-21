<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AppointmentDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ownerId = User::query()->where('email', 'owner@saloon.local')->value('id');

        $services = SalonService::query()
            ->whereIn('name', ['Luxury Haircut', 'Bridal Makeup', 'Hydrating Facial', 'Nail Art Premium'])
            ->get()
            ->keyBy('name');
        $staff = StaffProfile::query()
            ->with('user:id,name,email')
            ->whereHas('user', fn ($query) => $query->where('email', 'like', 'staff%@vina.local'))
            ->orderBy('employee_code')
            ->take(4)
            ->get();
        $customers = Customer::query()
            ->where('email', 'like', 'customer%@vina.local')
            ->orderBy('id')
            ->take(6)
            ->get();

        if ($services->isEmpty() || $staff->isEmpty() || $customers->isEmpty()) {
            return;
        }

        $now = Carbon::now()->startOfHour();
        $appointments = [
            ['customer_idx' => 0, 'staff_idx' => 0, 'service' => 'Luxury Haircut', 'start' => $now->copy()->subDays(2)->setTime(11, 0), 'status' => Appointment::STATUS_COMPLETED],
            ['customer_idx' => 1, 'staff_idx' => 1, 'service' => 'Bridal Makeup', 'start' => $now->copy()->subDay()->setTime(14, 0), 'status' => Appointment::STATUS_COMPLETED],
            ['customer_idx' => 2, 'staff_idx' => 2, 'service' => 'Hydrating Facial', 'start' => $now->copy()->subDay()->setTime(16, 0), 'status' => Appointment::STATUS_NO_SHOW],
            ['customer_idx' => 3, 'staff_idx' => 0, 'service' => 'Nail Art Premium', 'start' => $now->copy()->setTime(10, 0), 'status' => Appointment::STATUS_CONFIRMED],
            ['customer_idx' => 4, 'staff_idx' => 1, 'service' => 'Luxury Haircut', 'start' => $now->copy()->setTime(12, 0), 'status' => Appointment::STATUS_IN_PROGRESS],
            ['customer_idx' => 5, 'staff_idx' => 2, 'service' => 'Hydrating Facial', 'start' => $now->copy()->setTime(15, 0), 'status' => Appointment::STATUS_PENDING],
            ['customer_idx' => 0, 'staff_idx' => 3, 'service' => 'Bridal Makeup', 'start' => $now->copy()->addDay()->setTime(11, 0), 'status' => Appointment::STATUS_CONFIRMED],
            ['customer_idx' => 2, 'staff_idx' => 1, 'service' => 'Nail Art Premium', 'start' => $now->copy()->addDay()->setTime(13, 0), 'status' => Appointment::STATUS_PENDING],
            ['customer_idx' => 4, 'staff_idx' => 0, 'service' => 'Luxury Haircut', 'start' => $now->copy()->addDays(2)->setTime(12, 0), 'status' => Appointment::STATUS_CONFIRMED],
            ['customer_idx' => 1, 'staff_idx' => 3, 'service' => 'Hydrating Facial', 'start' => $now->copy()->addDays(3)->setTime(17, 0), 'status' => Appointment::STATUS_CANCELLED],
        ];

        foreach ($appointments as $row) {
            $customer = $customers[$row['customer_idx']] ?? null;
            $staffProfile = $staff[$row['staff_idx']] ?? null;
            $service = $services->get($row['service']);

            if (! $customer || ! $staffProfile || ! $service) {
                continue;
            }

            $start = $row['start'];
            $end = $start->copy()->addMinutes((int) $service->duration_minutes + (int) $service->buffer_minutes);
            $referenceNote = 'SEED-APPT-' . $start->format('YmdHi') . '-' . $customer->id;

            Appointment::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'scheduled_start' => $start,
                    'service_id' => $service->id,
                ],
                [
                    'staff_profile_id' => $staffProfile->id,
                    'booked_by' => $ownerId,
                    'source' => 'admin',
                    'status' => $row['status'],
                    'scheduled_end' => $end,
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
                    'customer_email' => $customer->email,
                    'cancellation_reason' => $row['status'] === Appointment::STATUS_CANCELLED ? 'Client requested reschedule' : null,
                    'notes' => $referenceNote,
                ]
            );
        }
    }
}
