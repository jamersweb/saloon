<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingRule;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppointmentServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_notifies_assigned_staff_via_whatsapp_log(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $staffUser = User::factory()->create(['role_id' => $managerRole->id, 'name' => 'Mona Bassagh']);

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-NOTIFY-01',
            'phone' => '971500001111',
            'is_active' => true,
        ]);
        StaffSchedule::create([
            'staff_profile_id' => $staff->id,
            'schedule_date' => '2026-05-12',
            'start_time' => '09:00',
            'end_time' => '22:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Lashes Refill',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 120,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('appointments.store'), [
            'customer_name' => 'Notify Client',
            'customer_phone' => '971500009999',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'staff_profile_id' => $staff->id,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 15:00:00',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('communication_logs', [
            'channel' => 'whatsapp',
            'recipient' => '971500001111',
            'status' => 'queued',
        ]);

        $log = CommunicationLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('you have a new appointment', (string) $log->message);
        $this->assertStringContainsString('Lashes Refill', (string) $log->message);
    }

    public function test_update_notifies_newly_assigned_staff_via_whatsapp_log(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $staffUser = User::factory()->create(['role_id' => $managerRole->id, 'name' => 'Hengameh Dortaj']);

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-NOTIFY-02',
            'phone' => '971500002222',
            'is_active' => true,
        ]);
        StaffSchedule::create([
            'staff_profile_id' => $staff->id,
            'schedule_date' => '2026-05-12',
            'start_time' => '09:00',
            'end_time' => '22:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Acrylic Gel Refill',
            'duration_minutes' => 80,
            'buffer_minutes' => 0,
            'price' => 250,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'service_id' => $service->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-05-12 16:00:00',
            'scheduled_end' => '2026-05-12 17:20:00',
            'customer_name' => 'Reassign Client',
            'customer_phone' => '971500008888',
        ]);

        $this->actingAs($manager)->put(route('appointments.update', $appointment), [
            'customer_name' => 'Reassign Client',
            'customer_phone' => '971500008888',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'staff_profile_id' => $staff->id,
            'scheduled_start' => '2026-05-12 16:00:00',
            'scheduled_end' => '2026-05-12 17:20:00',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('communication_logs', [
            'channel' => 'whatsapp',
            'recipient' => '971500002222',
            'status' => 'queued',
        ]);
    }

    public function test_update_replaces_services_for_existing_multi_service_visit(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $originalService = SalonService::create([
            'name' => 'Original Blowdry',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 80,
            'is_active' => true,
        ]);
        $staleService = SalonService::create([
            'name' => 'Stale Manicure',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 90,
            'is_active' => true,
        ]);
        $replacementService = SalonService::create([
            'name' => 'Replacement Blowdry Long',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 120,
            'is_active' => true,
        ]);

        $visitId = '11111111-1111-1111-1111-111111111111';
        $appointment = Appointment::create([
            'visit_id' => $visitId,
            'service_id' => $originalService->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 14:45:00',
            'customer_name' => 'Change Mind Client',
            'customer_phone' => '971500007777',
        ]);
        $staleAppointment = Appointment::create([
            'visit_id' => $visitId,
            'service_id' => $staleService->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-05-12 14:45:00',
            'scheduled_end' => '2026-05-12 15:30:00',
            'customer_name' => 'Change Mind Client',
            'customer_phone' => '971500007777',
        ]);

        $this->actingAs($manager)->put(route('appointments.update', $appointment), [
            'customer_name' => 'Change Mind Client',
            'customer_phone' => '971500007777',
            'service_id' => $replacementService->id,
            'service_ids' => [$replacementService->id],
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 15:00:00',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $appointment->refresh();

        $this->assertSame($replacementService->id, $appointment->service_id);
        $this->assertSame(1, Appointment::query()->where('visit_id', $visitId)->count());
        $this->assertDatabaseMissing('appointments', ['id' => $staleAppointment->id]);
    }

    public function test_staff_can_start_service_with_before_photo_and_notes(): void
    {
        Storage::fake('public');

        [$user, $staffProfile] = $this->createStaffUser();
        $service = SalonService::create([
            'name' => 'Hair Color',
            'duration_minutes' => 90,
            'buffer_minutes' => 15,
            'price' => 200,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(3),
            'customer_name' => 'Workflow Customer',
            'customer_phone' => '5554441111',
        ]);

        $response = $this->actingAs($user)->post(route('appointments.service-start', $appointment), [
            'intake_notes' => 'Customer requested softer tones.',
            'service_notes' => 'Patch test completed.',
            'before_photo' => UploadedFile::fake()->image('before.jpg'),
        ]);

        $response->assertSessionHasNoErrors();

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_IN_PROGRESS, $appointment->status);
        $this->assertNotNull($appointment->arrival_time);
        $this->assertNotNull($appointment->service_start_time);

        $this->assertDatabaseHas('appointment_service_logs', [
            'appointment_id' => $appointment->id,
            'intake_notes' => 'Customer requested softer tones.',
            'service_notes' => 'Patch test completed.',
        ]);

        $photo = $appointment->photos()->first();
        $this->assertNotNull($photo);
        $this->assertSame('before', $photo->type);
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_staff_can_complete_service_with_report_products_and_after_photo(): void
    {
        Storage::fake('public');

        [$user, $staffProfile] = $this->createStaffUser();
        $service = SalonService::create([
            'name' => 'Extension Service',
            'duration_minutes' => 120,
            'buffer_minutes' => 0,
            'price' => 450,
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'CUST-SVC-2001',
            'name' => 'Service Customer',
            'phone' => '5552223333',
            'is_active' => true,
        ]);

        $item = InventoryItem::create([
            'sku' => 'INV-COLOR-01',
            'name' => 'Premium Color Mix',
            'category' => 'Color',
            'unit' => 'tube',
            'cost_price' => 20,
            'selling_price' => 35,
            'stock_quantity' => 10,
            'reorder_level' => 2,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'arrival_time' => now()->subHour(),
            'service_start_time' => now()->subMinutes(50),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        $response = $this->actingAs($user)->post(route('appointments.service-complete', $appointment), [
            'service_report' => 'Service completed successfully with finishing serum.',
            'completion_notes' => 'Recommend maintenance after 6 weeks.',
            'materials_used' => 'Color mix, serum, toner.',
            'after_photo' => UploadedFile::fake()->image('after.jpg'),
            'products' => [
                [
                    'inventory_item_id' => $item->id,
                    'quantity' => 2,
                    'notes' => 'Used across full treatment.',
                ],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->status);
        $this->assertSame('Service completed successfully with finishing serum.', $appointment->notes);

        $this->assertDatabaseHas('appointment_service_logs', [
            'appointment_id' => $appointment->id,
            'completion_notes' => 'Recommend maintenance after 6 weeks.',
            'materials_used' => 'Color mix, serum, toner.',
        ]);

        $this->assertDatabaseHas('appointment_product_usages', [
            'appointment_id' => $appointment->id,
            'inventory_item_id' => $item->id,
            'quantity' => 2,
        ]);

        $photo = $appointment->photos()->where('type', 'after')->first();
        $this->assertNotNull($photo);
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_staff_only_sees_their_own_appointments_on_the_appointments_screen(): void
    {
        [$user, $staffProfile] = $this->createStaffUser();

        $otherStaffUser = User::factory()->create([
            'role_id' => $user->role_id,
        ]);
        $otherStaffProfile = StaffProfile::create([
            'user_id' => $otherStaffUser->id,
            'employee_code' => 'STF-WORK-02',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Hair Cut',
            'category' => 'Hair',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 90,
            'is_active' => true,
        ]);

        $mine = Appointment::create([
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
            'customer_name' => 'My Client',
            'customer_phone' => '5551110001',
        ]);

        Appointment::create([
            'service_id' => $service->id,
            'staff_profile_id' => $otherStaffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => now()->addHours(3),
            'scheduled_end' => now()->addHours(4),
            'customer_name' => 'Other Client',
            'customer_phone' => '5551110002',
        ]);

        $this->actingAs($user)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Appointments/Index')
                ->has('appointments', 1)
                ->where('appointments.0.id', $mine->id)
                ->where('appointments.0.customer_name', 'My Client')
            );
    }

    public function test_starting_a_future_booking_uses_the_actual_service_time(): void
    {
        $actualStart = Carbon::parse('2026-05-15 12:20:29');

        Carbon::setTestNow($actualStart);

        try {
            [$user, $staffProfile] = $this->createStaffUser();

            $service = SalonService::create([
                'name' => 'Protein Therapy',
                'category' => 'Hair',
                'duration_minutes' => 30,
                'buffer_minutes' => 0,
                'price' => 150,
                'is_active' => true,
            ]);

            $appointment = Appointment::create([
                'service_id' => $service->id,
                'staff_profile_id' => $staffProfile->id,
                'source' => 'admin',
                'status' => Appointment::STATUS_CONFIRMED,
                'scheduled_start' => '2026-05-18 12:00:00',
                'scheduled_end' => '2026-05-18 12:30:00',
                'customer_name' => 'Negin Nordoukhani',
                'customer_phone' => '971509544424',
            ]);

            $this->actingAs($user)
                ->post(route('appointments.service-start', $appointment), [
                    'intake_notes' => '',
                    'service_notes' => '',
                ])
                ->assertSessionHasNoErrors();

            $appointment->refresh();

            $this->assertSame(Appointment::STATUS_IN_PROGRESS, $appointment->status);
            $this->assertSame('2026-05-15 12:20:29', $appointment->scheduled_start->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-15 12:50:29', $appointment->scheduled_end->format('Y-m-d H:i:s'));
            $this->assertSame('2026-05-15 12:20:29', $appointment->serviceExecution->started_at->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createStaffUser(): array
    {
        $role = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $staffProfile = StaffProfile::create([
            'user_id' => $user->id,
            'employee_code' => 'STF-WORK-01',
            'is_active' => true,
        ]);

        return [$user, $staffProfile];
    }
}
