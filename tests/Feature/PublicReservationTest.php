<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingRule;
use App\Models\PublicReservation;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Services\PublicReservationAvailability;
use App\Services\PublicReservationPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicReservationTest extends TestCase
{
    use RefreshDatabase;

    private StaffProfile $staff;

    private SalonService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(8, 0));
        config(['public_booking.nomod_key' => 'test-only']);
        Mail::fake();
        Http::preventStrayRequests();
        $this->staff = StaffProfile::create(['user_id' => User::factory()->create()->id, 'employee_code' => 'PUBLIC-TEST', 'is_active' => true]);
        $this->service = SalonService::create(['name' => 'Nail Refill', 'category' => 'Nails', 'duration_minutes' => 90, 'buffer_minutes' => 0, 'price' => 150, 'is_active' => true]);
        BookingRule::current()->update(['min_advance_minutes' => 30, 'max_advance_days' => 60, 'opening_time' => '09:00', 'closing_time' => '22:00']);
        foreach ([13, 14, 15] as $day) {
            StaffSchedule::create(['staff_profile_id' => $this->staff->id, 'schedule_date' => "2026-11-$day", 'start_time' => '09:00', 'end_time' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00', 'is_day_off' => false]);
        }
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['service_ids' => [$this->service->id], 'start' => '2026-11-13T13:00', 'staff_id' => $this->staff->id,
            'customer_name' => 'Test Guest', 'customer_email' => 'guest@example.test', 'customer_phone' => '+971501234567'], $extra);
    }

    private function checkout(array $extra = []): PublicReservation
    {
        Http::fake(['api.nomod.com/v1/checkout' => Http::response(['id' => 'checkout-1', 'url' => 'https://pay.nomod.com/test'])]);
        $this->postJson('/book', $this->payload($extra))->assertOk();

        return PublicReservation::firstOrFail();
    }

    private function paid(PublicReservation $booking, array $override = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api.nomod.com/v1/checkout/*' => Http::response(array_merge(['id' => $booking->checkout_id,
            'reference_id' => 'public-booking-'.$booking->id, 'status' => 'paid', 'amount' => $booking->deposit_minor / 100, 'currency' => 'AED'], $override))]);
    }

    public function test_public_pages_use_real_catalogue_without_search(): void
    {
        foreach (['/book', '/embed/book'] as $url) {
            $this->get($url)->assertOk()->assertSee('Nail Refill')->assertSee('categories')->assertDontSee('type="search"', false);
        }
    }

    public function test_two_guests_can_book_the_same_treatment_with_one_deposit(): void
    {
        $booking = $this->checkout(['guests' => [[$this->service->id], [$this->service->id]]]);
        $this->assertSame(['Booking guest', 'Guest 2'], array_column($booking->services, 'guest'));
        $this->assertEquals(180, $booking->duration_minutes);
        $this->assertEquals(30000, $booking->total_minor);
        $this->assertEquals(4500, $booking->deposit_minor);
    }

    public function test_deposit_is_calculated_server_side_and_portal_is_untouched(): void
    {
        $booking = $this->checkout(['total_minor' => 1, 'deposit_minor' => 1]);
        $this->assertEquals(15000, $booking->total_minor);
        $this->assertEquals(2250, $booking->deposit_minor);
        $this->assertSame('awaiting_payment', $booking->status);
        $this->assertDatabaseCount('appointments', 0);
        Http::assertSent(fn ($request) => $request['amount'] === '22.50' && $request['currency'] === 'AED');
        $this->postJson('/book', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('public_reservations', 1);
    }

    public function test_multiple_services_and_preparation_extend_the_end_time(): void
    {
        $gel = SalonService::create(['name' => 'Gel Polish', 'duration_minutes' => 45, 'buffer_minutes' => 10, 'price' => 80, 'is_active' => true]);
        $booking = $this->checkout(['service_ids' => [$this->service->id, $gel->id]]);
        $this->assertEquals(145, $booking->duration_minutes);
        $this->assertEquals('15:25', $booking->ends_at->format('H:i'));
        $this->assertEquals(3450, $booking->deposit_minor);
    }

    public function test_slots_include_exact_previous_end_and_exclude_breaks_and_shift_end(): void
    {
        $booking = $this->checkout();
        $booking->update(['ends_at' => '2026-11-13 14:45:00']);
        $slots = app(PublicReservationAvailability::class)->slots('2026-11-13', 90);
        $starts = array_column($slots, 'start');
        $this->assertContains('2026-11-13T14:45', $starts);
        foreach (['2026-11-13T11:30', '2026-11-13T13:30', '2026-11-13T16:00'] as $start) {
            $this->assertNotContains($start, $starts);
        }
    }

    public function test_portal_appointments_are_read_for_conflicts(): void
    {
        Appointment::create(['service_id' => $this->service->id, 'staff_profile_id' => $this->staff->id, 'source' => 'internal', 'status' => 'confirmed', 'scheduled_start' => '2026-11-13 13:00', 'scheduled_end' => '2026-11-13 14:30', 'customer_name' => 'Portal Guest', 'customer_phone' => '123456789']);
        $this->postJson('/book', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('public_reservations', 0);
    }

    public function test_only_verified_payment_confirms_and_repeated_returns_are_idempotent(): void
    {
        $booking = $this->checkout();
        $this->paid($booking);
        $payments = app(PublicReservationPayments::class);
        $payments->reconcile($booking);
        $payments->reconcile($booking->fresh());
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->paid_at);
        $this->assertDatabaseCount('appointments', 0);
        $this->get('/book/manage/'.$booking->token)->assertOk()->assertSee('AED 22.50')->assertSee('AED 127.50');
    }

    public function test_mismatched_payment_amount_cannot_confirm(): void
    {
        $booking = $this->checkout();
        $this->paid($booking, ['amount' => 1]);
        $this->get('/book/manage/'.$booking->token)->assertOk()->assertSee('awaiting payment');
        $this->assertSame('awaiting_payment', $booking->fresh()->status);
    }

    public function test_missing_payment_credentials_fail_closed(): void
    {
        config(['public_booking.nomod_key' => null]);
        $this->postJson('/book', $this->payload())->assertStatus(503);
        $this->assertDatabaseCount('public_reservations', 0);
    }

    public function test_rescheduling_moves_reservation_and_keeps_deposit(): void
    {
        $booking = $this->checkout();
        $this->paid($booking);
        app(PublicReservationPayments::class)->reconcile($booking);
        $this->postJson('/book/manage/'.$booking->token, ['start' => '2026-11-14T13:00', 'staff_id' => $this->staff->id])->assertOk();
        $this->assertEquals('2026-11-14 13:00', $booking->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertEquals(2250, $booking->fresh()->deposit_minor);
        $this->assertTrue(app(PublicReservationAvailability::class)->available($this->staff->id, now()->setDate(2026, 11, 13)->setTime(13, 0), 90));
        $this->assertDatabaseCount('public_reservations', 1);
    }

    public function test_late_rescheduling_waits_for_manager_and_retains_original(): void
    {
        $booking = $this->checkout();
        $this->paid($booking);
        app(PublicReservationPayments::class)->reconcile($booking);
        $this->travelTo(now()->setDate(2026, 11, 13)->setTime(10, 0));
        $this->postJson('/book/manage/'.$booking->token, ['start' => '2026-11-14T13:00', 'staff_id' => $this->staff->id])->assertOk()->assertJsonPath('message', 'Your request is awaiting management approval. Your original appointment remains reserved.');
        $this->assertEquals('2026-11-13 13:00', $booking->fresh()->starts_at->format('Y-m-d H:i'));
        $role = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $manager = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($manager)->post('/public-booking-management/'.$booking->id.'/decision', ['decision' => 'approve'])->assertRedirect();
        $this->assertEquals('2026-11-14 13:00', $booking->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_rescheduling_outside_fourteen_days_is_rejected(): void
    {
        $booking = $this->checkout();
        $this->paid($booking);
        app(PublicReservationPayments::class)->reconcile($booking);
        $this->postJson('/book/manage/'.$booking->token, ['start' => '2026-12-13T13:00', 'staff_id' => $this->staff->id])->assertUnprocessable();
    }

    public function test_management_is_not_public(): void
    {
        $this->get('/public-booking-management')->assertRedirect('/login');
        $this->get('/book/manage/'.str_repeat('x', 64))->assertNotFound();
    }

    public function test_public_picker_omits_managers_and_the_duplicate_mona_profile(): void
    {
        $manager = Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager']);
        foreach (['VINA-01', 'EMP-108', 'MANAGER-TEST'] as $code) {
            $user = User::factory()->create([
                'name' => $code === 'MANAGER-TEST' ? 'Manager Example' : 'Mona Bassagh',
                'role_id' => $code === 'MANAGER-TEST' ? $manager->id : null,
            ]);
            StaffProfile::create(['user_id' => $user->id, 'employee_code' => $code, 'is_active' => true]);
        }

        $page = $this->get('/embed/book')->assertOk()->assertDontSee('Manager Example');
        $this->assertSame(1, substr_count($page->getContent(), '<strong>Mona Bassagh</strong>'));
        $this->assertSame(['EMP-108'], StaffProfile::bookableOnline()
            ->whereIn('employee_code', ['VINA-01', 'EMP-108', 'MANAGER-TEST'])->pluck('employee_code')->all());
    }

    public function test_leave_and_non_bookable_roles_are_rejected(): void
    {
        \App\Models\LeaveRequest::create(['staff_profile_id' => $this->staff->id, 'start_date' => '2026-11-13', 'end_date' => '2026-11-13', 'reason' => 'Leave', 'status' => 'approved']);
        $this->postJson('/book', $this->payload())->assertUnprocessable();
        $role = Role::firstOrCreate(['name' => 'reception'], ['label' => 'Reception']);
        $this->staff->user->update(['role_id' => $role->id]);
        $this->postJson('/book', $this->payload(['start' => '2026-11-14T13:00']))->assertUnprocessable();
    }

    public function test_public_booking_does_not_create_missing_staff_shifts_or_update_customers(): void
    {
        $customer = \App\Models\Customer::create(['customer_code' => 'SAFE-1', 'name' => 'Original', 'phone' => '+971501234567', 'email' => 'original@example.test']);
        $this->postJson('/book', $this->payload(['start' => '2026-11-16T13:00']))->assertUnprocessable();
        $this->assertDatabaseMissing('staff_schedules', ['schedule_date' => '2026-11-16']);
        $this->checkout();
        $this->assertSame('Original', $customer->fresh()->name);
        $this->assertSame('original@example.test', $customer->fresh()->email);
    }

    public function test_reschedule_conflict_retains_original_slot_and_payment(): void
    {
        $booking = $this->checkout();
        $this->paid($booking);
        app(PublicReservationPayments::class)->reconcile($booking);
        $other = $booking->replicate(['token', 'checkout_id']);
        $other->token = str_repeat('z', 64);
        $other->checkout_id = null;
        $other->starts_at = '2026-11-14 13:00';
        $other->ends_at = '2026-11-14 14:30';
        $other->save();
        $this->postJson('/book/manage/'.$booking->token, ['start' => '2026-11-14T13:00', 'staff_id' => $this->staff->id])->assertUnprocessable();
        $this->assertEquals('2026-11-13 13:00', $booking->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertEquals(2250, $booking->fresh()->deposit_minor);
    }

    public function test_cancelled_checkout_releases_hold_but_return_query_does_not_confirm(): void
    {
        $booking = $this->checkout();
        $this->paid($booking, ['status' => 'created']);
        $this->get('/book/manage/'.$booking->token.'?status=paid')->assertOk();
        $this->assertSame('awaiting_payment', $booking->fresh()->status);
        $this->paid($booking, ['status' => 'cancelled']);
        app(PublicReservationPayments::class)->reconcile($booking->fresh());
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertTrue(app(PublicReservationAvailability::class)->available($this->staff->id, now()->setDate(2026, 11, 13)->setTime(13, 0), 90));
    }
}
