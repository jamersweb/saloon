<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppointmentServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
