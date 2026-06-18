<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingRule;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\InvoicePayment;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\TaxInvoice;
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

    public function test_store_uses_selected_existing_customer_id_from_drawer(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $staffUser = User::factory()->create(['role_id' => $managerRole->id, 'name' => 'Raheel Staff']);

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-CLIENT-ID',
            'is_active' => true,
        ]);
        StaffSchedule::create([
            'staff_profile_id' => $staff->id,
            'schedule_date' => '2026-05-12',
            'start_time' => '09:00',
            'end_time' => '22:00',
            'is_day_off' => false,
        ]);

        $customer = Customer::create([
            'customer_code' => 'CUST-SELECTED-ID',
            'name' => 'Sonya Soltani',
            'phone' => '0559777560',
            'is_active' => true,
        ]);
        $service = SalonService::create([
            'name' => 'Existing Client Service',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 150,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('appointments.store'), [
            'customer_id' => $customer->id,
            'customer_name' => 'Sonya Soltani',
            'customer_phone' => '',
            'customer_email' => '',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'staff_profile_id' => $staff->id,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 15:00:00',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $appointment = Appointment::query()->latest('id')->first();
        $this->assertNotNull($appointment);
        $this->assertSame($customer->id, $appointment->customer_id);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_store_auto_fills_missing_staff_schedule_for_selected_staff(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $staffUser = User::factory()->create(['role_id' => $managerRole->id, 'name' => 'Majd Alabaza']);

        BookingRule::create([
            'opening_time' => '10:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'VINA-03',
            'is_active' => true,
        ]);
        $service = SalonService::create([
            'name' => 'Blowdry Long and thick',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 175,
            'is_active' => true,
        ]);

        $this->assertSame(0, StaffSchedule::query()->count());

        $this->actingAs($manager)->post(route('appointments.store'), [
            'customer_name' => 'Sonya Soltani',
            'customer_phone' => '0559777560',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'staff_profile_id' => $staff->id,
            'scheduled_start' => '2026-06-08 17:00:00',
            'scheduled_end' => '2026-06-08 18:00:00',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('appointments', [
            'customer_name' => 'Sonya Soltani',
            'staff_profile_id' => $staff->id,
            'service_id' => $service->id,
        ]);
        $this->assertTrue(
            StaffSchedule::query()
                ->where('staff_profile_id', $staff->id)
                ->whereDate('schedule_date', '2026-06-08')
                ->where('is_day_off', false)
                ->exists(),
        );
    }

    public function test_store_persists_per_service_drawer_adjustments(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $staffUser = User::factory()->create(['role_id' => $managerRole->id, 'name' => 'Mariam Yousaf']);

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-DRAWER-01',
            'phone' => '971500003333',
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
            'duration_minutes' => 75,
            'buffer_minutes' => 0,
            'price' => 250,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('appointments.store'), [
            'customer_name' => 'Drawer Client',
            'customer_phone' => '971500007777',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'service_starts' => [$service->id => '2026-05-12 14:15:00'],
            'service_durations' => [$service->id => 90],
            'service_extra_minutes' => [$service->id => 15],
            'service_unit_prices' => [$service->id => 275],
            'service_discount_amounts' => [$service->id => 25],
            'staff_assignments' => [$service->id => $staff->id],
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 15:15:00',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $appointment = Appointment::query()->where('customer_phone', '971500007777')->firstOrFail();

        $this->assertSame($staff->id, $appointment->staff_profile_id);
        $this->assertSame('2026-05-12 14:15:00', $appointment->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 16:00:00', $appointment->scheduled_end->format('Y-m-d H:i:s'));
        $this->assertSame('275.00', (string) $appointment->service_unit_price);
        $this->assertSame('25.00', (string) $appointment->service_discount_amount);
        $this->assertSame(90, $appointment->service_duration_minutes);
        $this->assertSame(15, $appointment->service_extra_minutes);
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

    public function test_update_scheduled_start_wins_over_stale_first_service_start(): void
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
            'customer_name' => 'Timing Client',
            'customer_phone' => '971500006666',
        ]);

        $this->actingAs($manager)->put(route('appointments.update', $appointment), [
            'customer_name' => 'Timing Client',
            'customer_phone' => '971500006666',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'service_starts' => ['line_0' => '2026-05-12 16:00:00'],
            'scheduled_start' => '2026-05-12 17:40:00',
            'scheduled_end' => '2026-05-12 19:00:00',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $appointment->refresh();

        $this->assertSame('2026-05-12 17:40:00', $appointment->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 19:00:00', $appointment->scheduled_end->format('Y-m-d H:i:s'));
    }

    public function test_board_move_updates_staff_and_time(): void
    {
        Queue::fake();

        $manager = $this->createManagerUser();
        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);
        $firstStaffUser = User::factory()->create(['role_id' => $staffRole->id, 'name' => 'First Staff']);
        $secondStaffUser = User::factory()->create(['role_id' => $staffRole->id, 'name' => 'Second Staff']);

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 15,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $firstStaff = StaffProfile::create([
            'user_id' => $firstStaffUser->id,
            'employee_code' => 'BOARD-01',
            'is_active' => true,
        ]);
        $secondStaff = StaffProfile::create([
            'user_id' => $secondStaffUser->id,
            'employee_code' => 'BOARD-02',
            'is_active' => true,
        ]);

        foreach ([$firstStaff, $secondStaff] as $staff) {
            StaffSchedule::create([
                'staff_profile_id' => $staff->id,
                'schedule_date' => '2026-05-12',
                'start_time' => '09:00',
                'end_time' => '22:00',
                'is_day_off' => false,
            ]);
        }

        $service = SalonService::create([
            'name' => 'Nail Polish',
            'duration_minutes' => 15,
            'buffer_minutes' => 0,
            'price' => 50,
            'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'service_id' => $service->id,
            'staff_profile_id' => $firstStaff->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 14:15:00',
            'customer_name' => 'Board Client',
            'customer_phone' => '971500006001',
        ]);

        $this->actingAs($manager)->patch(route('appointments.board-move', $appointment), [
            'staff_profile_id' => $secondStaff->id,
            'scheduled_start' => '2026-05-12 15:30:00',
        ])->assertSessionHasNoErrors();

        $appointment->refresh();

        $this->assertSame($secondStaff->id, $appointment->staff_profile_id);
        $this->assertSame('2026-05-12 15:30:00', $appointment->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 15:45:00', $appointment->scheduled_end->format('Y-m-d H:i:s'));
    }

    public function test_board_move_rejects_staff_day_off(): void
    {
        Queue::fake();

        $manager = $this->createManagerUser();
        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);
        $staffUser = User::factory()->create(['role_id' => $staffRole->id, 'name' => 'Off Staff']);

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 15,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'BOARD-OFF',
            'is_active' => true,
        ]);
        StaffSchedule::create([
            'staff_profile_id' => $staff->id,
            'schedule_date' => '2026-05-12',
            'start_time' => '09:00',
            'end_time' => '22:00',
            'is_day_off' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Nail Polish',
            'duration_minutes' => 15,
            'buffer_minutes' => 0,
            'price' => 50,
            'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'service_id' => $service->id,
            'staff_profile_id' => null,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 14:15:00',
            'customer_name' => 'Board Client',
            'customer_phone' => '971500006002',
        ]);

        $this->actingAs($manager)->from(route('appointments.index'))->patch(route('appointments.board-move', $appointment), [
            'staff_profile_id' => $staff->id,
            'scheduled_start' => '2026-05-12 15:30:00',
        ])->assertSessionHasErrors('staff_profile_id');

        $appointment->refresh();

        $this->assertNull($appointment->staff_profile_id);
        $this->assertSame('2026-05-12 14:00:00', $appointment->scheduled_start->format('Y-m-d H:i:s'));
    }

    public function test_staff_can_be_assigned_to_multiple_clients_at_same_time(): void
    {
        Queue::fake();

        $manager = $this->createManagerUser();

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staffUser = User::factory()->create(['role_id' => $manager->role_id, 'name' => 'Parallel Staff']);
        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-PARALLEL',
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
            'name' => 'Parallel Blowdry',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 150,
            'is_active' => true,
        ]);

        Appointment::create([
            'customer_name' => 'First Client',
            'customer_phone' => '971500000001',
            'service_id' => $service->id,
            'staff_profile_id' => $staff->id,
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 14:45:00',
            'source' => 'admin',
        ]);

        $this->actingAs($manager)->post(route('appointments.store'), [
            'customer_name' => 'Second Client',
            'customer_phone' => '971500000002',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'staff_profile_id' => $staff->id,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 14:45:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Appointment::query()
            ->where('staff_profile_id', $staff->id)
            ->where('service_id', $service->id)
            ->where('scheduled_start', '2026-05-12 14:00:00')
            ->count());
    }

    public function test_single_client_can_repeat_same_service_with_quantity(): void
    {
        Queue::fake();

        $manager = $this->createManagerUser();

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staffUser = User::factory()->create(['role_id' => $manager->role_id, 'name' => 'Repeat Staff']);
        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-REPEAT',
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
            'name' => 'Repeat Wash',
            'duration_minutes' => 20,
            'buffer_minutes' => 0,
            'price' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('appointments.store'), [
            'customer_name' => 'Repeat Client',
            'customer_phone' => '',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'service_quantities' => [$service->id => 3],
            'staff_profile_id' => $staff->id,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 15:00:00',
            'status' => Appointment::STATUS_CONFIRMED,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('appointments', [
            'customer_name' => 'Repeat Client',
            'service_id' => $service->id,
            'service_quantity' => 3,
        ]);
    }

    public function test_single_client_can_repeat_same_service_as_separate_timed_rows(): void
    {
        Queue::fake();

        $manager = $this->createManagerUser();

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 15,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staffUser = User::factory()->create(['role_id' => $manager->role_id, 'name' => 'Repeat Line Staff']);
        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-REPEAT-LINE',
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
            'name' => 'Repeat Gel Overlay',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 150,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('appointments.store'), [
            'customer_name' => 'Repeat Line Client',
            'customer_phone' => '971500004444',
            'service_id' => $service->id,
            'service_ids' => [$service->id, $service->id],
            'service_starts' => [
                'line_0' => '2026-05-12 14:00:00',
                'line_1' => '2026-05-12 16:15:00',
            ],
            'service_durations' => [
                'line_0' => 45,
                'line_1' => 30,
            ],
            'service_extra_minutes' => [
                'line_1' => 15,
            ],
            'service_unit_prices' => [
                'line_0' => 150,
                'line_1' => 125,
            ],
            'service_discount_amounts' => [
                'line_1' => 25,
            ],
            'staff_assignments' => [
                'line_0' => $staff->id,
                'line_1' => $staff->id,
            ],
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 17:00:00',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $appointments = Appointment::query()
            ->where('customer_phone', '971500004444')
            ->orderBy('scheduled_start')
            ->get();

        $this->assertCount(2, $appointments);
        $this->assertSame($service->id, $appointments[0]->service_id);
        $this->assertSame($service->id, $appointments[1]->service_id);
        $this->assertSame('2026-05-12 14:00:00', $appointments[0]->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 14:45:00', $appointments[0]->scheduled_end->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 16:15:00', $appointments[1]->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 17:00:00', $appointments[1]->scheduled_end->format('Y-m-d H:i:s'));
        $this->assertSame('125.00', (string) $appointments[1]->service_unit_price);
        $this->assertSame('25.00', (string) $appointments[1]->service_discount_amount);
        $this->assertSame(30, $appointments[1]->service_duration_minutes);
        $this->assertSame(15, $appointments[1]->service_extra_minutes);
    }

    public function test_single_client_can_repeat_same_service_at_same_time_with_same_staff(): void
    {
        Queue::fake();

        $manager = $this->createManagerUser();

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 15,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staffUser = User::factory()->create(['role_id' => $manager->role_id, 'name' => 'Parallel Line Staff']);
        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-PARALLEL-LINE',
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
            'name' => 'Parallel Gel Overlay',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 150,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('appointments.store'), [
            'customer_name' => 'Parallel Line Client',
            'customer_phone' => '971500009999',
            'service_id' => $service->id,
            'service_ids' => [$service->id, $service->id],
            'service_starts' => [
                'line_0' => '2026-05-12 14:00:00',
                'line_1' => '2026-05-12 14:00:00',
            ],
            'staff_assignments' => [
                'line_0' => $staff->id,
                'line_1' => $staff->id,
            ],
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 14:45:00',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $appointments = Appointment::query()
            ->where('customer_phone', '971500009999')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $appointments);
        $this->assertSame($service->id, $appointments[0]->service_id);
        $this->assertSame($service->id, $appointments[1]->service_id);
        $this->assertSame($staff->id, $appointments[0]->staff_profile_id);
        $this->assertSame($staff->id, $appointments[1]->staff_profile_id);
        $this->assertSame('2026-05-12 14:00:00', $appointments[0]->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 14:00:00', $appointments[1]->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 14:45:00', $appointments[0]->scheduled_end->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-12 14:45:00', $appointments[1]->scheduled_end->format('Y-m-d H:i:s'));
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

    public function test_confirmed_service_can_be_completed_with_optional_report_and_additional_service_billing(): void
    {
        $manager = $this->createManagerUser();

        $primaryService = SalonService::create([
            'name' => 'Base Service',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 200,
            'is_active' => true,
        ]);
        $extraService = SalonService::create([
            'name' => 'Added Service',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 80,
            'is_active' => true,
        ]);
        $staffUser = User::factory()->create(['role_id' => $manager->role_id, 'name' => 'Extra Staff']);
        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-EXTRA',
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'customer_code' => 'CUST-EXTRA',
            'name' => 'Billing Customer',
            'phone' => '',
            'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $primaryService->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 15:00:00',
            'customer_name' => $customer->name,
            'customer_phone' => '',
        ]);

        $this->actingAs($manager)->post(route('appointments.service-complete', $appointment), [
            'service_report' => '',
            'create_tax_invoice_draft' => true,
            'additional_services' => [
                [
                    'service_id' => $extraService->id,
                    'staff_profile_id' => $staff->id,
                    'quantity' => 2,
                ],
            ],
        ])->assertSessionHasNoErrors();

        $appointment->refresh();
        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->status);
        $this->assertNotNull($appointment->visit_id);

        $extraAppointment = Appointment::query()
            ->where('visit_id', $appointment->visit_id)
            ->where('service_id', $extraService->id)
            ->first();

        $this->assertNotNull($extraAppointment);
        $this->assertSame(2, $extraAppointment->service_quantity);
        $this->assertSame($staff->id, $extraAppointment->staff_profile_id);

        $invoice = TaxInvoice::query()->where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(360.0, (float) $invoice->subtotal);
        $this->assertDatabaseHas('tax_invoice_items', [
            'tax_invoice_id' => $invoice->id,
            'salon_service_id' => $extraService->id,
            'quantity' => 2,
            'unit_price' => '80.00',
        ]);
    }

    public function test_multiple_visit_services_can_be_completed_together(): void
    {
        $manager = $this->createManagerUser();

        $firstService = SalonService::create([
            'name' => 'Gel Manicure',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 120,
            'is_active' => true,
        ]);
        $secondService = SalonService::create([
            'name' => 'Blowdry',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 180,
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'customer_code' => 'CUST-MULTI-FINISH',
            'name' => 'Multi Finish Customer',
            'phone' => '5557778888',
            'is_active' => true,
        ]);
        $visitId = '22222222-2222-2222-2222-222222222222';
        $firstAppointment = Appointment::create([
            'customer_id' => $customer->id,
            'visit_id' => $visitId,
            'service_id' => $firstService->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => '2026-05-12 14:00:00',
            'scheduled_end' => '2026-05-12 14:45:00',
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);
        $secondAppointment = Appointment::create([
            'customer_id' => $customer->id,
            'visit_id' => $visitId,
            'service_id' => $secondService->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-05-12 14:45:00',
            'scheduled_end' => '2026-05-12 15:45:00',
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        $this->actingAs($manager)->post(route('appointments.service-complete', $firstAppointment), [
            'service_report' => 'Visit finished together.',
            'completion_notes' => 'Both services finished at checkout.',
            'complete_visit_service_ids' => [$firstAppointment->id, $secondAppointment->id],
            'create_tax_invoice_draft' => true,
        ])->assertSessionHasNoErrors();

        $firstAppointment->refresh();
        $secondAppointment->refresh();

        $this->assertSame(Appointment::STATUS_COMPLETED, $firstAppointment->status);
        $this->assertSame(Appointment::STATUS_COMPLETED, $secondAppointment->status);
        $this->assertSame('Visit finished together.', $firstAppointment->notes);
        $this->assertSame('Visit finished together.', $secondAppointment->notes);
        $this->assertDatabaseHas('appointment_service_logs', [
            'appointment_id' => $firstAppointment->id,
            'completion_notes' => 'Both services finished at checkout.',
        ]);
        $this->assertDatabaseHas('appointment_service_logs', [
            'appointment_id' => $secondAppointment->id,
            'completion_notes' => 'Both services finished at checkout.',
        ]);

        $invoice = TaxInvoice::query()->where('appointment_id', $firstAppointment->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(300.0, (float) $invoice->subtotal);
        $this->assertDatabaseHas('tax_invoice_items', [
            'tax_invoice_id' => $invoice->id,
            'salon_service_id' => $firstService->id,
            'unit_price' => '120.00',
        ]);
        $this->assertDatabaseHas('tax_invoice_items', [
            'tax_invoice_id' => $invoice->id,
            'salon_service_id' => $secondService->id,
            'unit_price' => '180.00',
        ]);
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

    public function test_appointment_index_can_filter_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23 11:00:00'));

        try {
            $manager = $this->createManagerUser();
            $service = SalonService::create([
                'name' => 'Gel Polish',
                'category' => 'Nails',
                'duration_minutes' => 45,
                'buffer_minutes' => 0,
                'price' => 120,
                'is_active' => true,
            ]);

            Appointment::create([
                'service_id' => $service->id,
                'source' => 'admin',
                'status' => Appointment::STATUS_CONFIRMED,
                'scheduled_start' => '2026-05-22 14:00:00',
                'scheduled_end' => '2026-05-22 14:45:00',
                'customer_name' => 'Old Client',
                'customer_phone' => '5550000001',
            ]);

            $today = Appointment::create([
                'service_id' => $service->id,
                'source' => 'admin',
                'status' => Appointment::STATUS_CONFIRMED,
                'scheduled_start' => '2026-05-23 15:00:00',
                'scheduled_end' => '2026-05-23 15:45:00',
                'customer_name' => 'Today Client',
                'customer_phone' => '5550000002',
            ]);

            $this->actingAs($manager)
                ->get(route('appointments.index', ['status' => 'today']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Appointments/Index')
                    ->has('appointments', 1)
                    ->where('appointments.0.id', $today->id)
                    ->where('appointments.0.customer_name', 'Today Client')
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_appointment_index_can_filter_needs_pay(): void
    {
        $manager = $this->createManagerUser();
        $service = SalonService::create([
            'name' => 'Hair Treatment',
            'category' => 'Hair',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 200,
            'is_active' => true,
        ]);

        $needsPay = Appointment::create([
            'service_id' => $service->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now(),
            'customer_name' => 'Needs Pay Client',
            'customer_phone' => '5550000003',
        ]);

        $paid = Appointment::create([
            'service_id' => $service->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subHour(),
            'customer_name' => 'Paid Client',
            'customer_phone' => '5550000004',
        ]);

        $invoice = TaxInvoice::create([
            'appointment_id' => $paid->id,
            'customer_display_name' => 'Paid Client',
            'status' => TaxInvoice::STATUS_FINALIZED,
            'subtotal' => 200,
            'vat_amount' => 0,
            'total' => 200,
            'issued_at' => now(),
            'created_by' => $manager->id,
        ]);

        InvoicePayment::create([
            'tax_invoice_id' => $invoice->id,
            'amount' => 200,
            'method' => InvoicePayment::METHOD_CASH,
            'paid_at' => now(),
            'created_by' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->get(route('appointments.index', ['status' => 'needs_pay']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Appointments/Index')
                ->has('appointments', 1)
                ->where('appointments.0.id', $needsPay->id)
                ->where('appointments.0.awaiting_checkout', true)
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

    private function createManagerUser(): User
    {
        $role = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        return User::factory()->create(['role_id' => $role->id]);
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
