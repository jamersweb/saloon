<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StaffScheduleAutoFillAndLeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_leave_marks_schedule_days_off(): void
    {
        $manager = $this->makeManagerUser();
        $staff = StaffProfile::create([
            'user_id' => User::factory()->create()->id,
            'employee_code' => 'STF-LV-01',
            'is_active' => true,
        ]);

        $leaveStart = Carbon::now()->addDays(5)->toDateString();
        $leaveEnd = Carbon::now()->addDays(7)->toDateString();

        StaffSchedule::create([
            'staff_profile_id' => $staff->id,
            'schedule_date' => $leaveStart,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_day_off' => false,
            'notes' => 'Manual test shift',
        ]);

        $leave = LeaveRequest::create([
            'staff_profile_id' => $staff->id,
            'start_date' => $leaveStart,
            'end_date' => $leaveEnd,
            'reason' => 'Away',
            'status' => 'pending',
        ]);

        $this->actingAs($manager)
            ->patch(route('leave-requests.review', $leave), ['status' => 'approved'])
            ->assertRedirect();

        $leave->refresh();
        $this->assertSame('approved', $leave->status);

        $this->assertTrue(
            StaffSchedule::query()
                ->where('staff_profile_id', $staff->id)
                ->whereDate('schedule_date', $leaveStart)
                ->where('is_day_off', true)
                ->where('notes', 'Approved leave #'.$leave->id)
                ->exists(),
            'Expected leave start date to be marked day off on the schedule.',
        );

        $this->assertTrue(
            StaffSchedule::query()
                ->where('staff_profile_id', $staff->id)
                ->whereDate('schedule_date', $leaveEnd)
                ->where('is_day_off', true)
                ->where('notes', 'Approved leave #'.$leave->id)
                ->exists(),
            'Expected leave end date to be marked day off on the schedule.',
        );
    }

    public function test_rejecting_approved_leave_restores_default_shift_row(): void
    {
        $manager = $this->makeManagerUser();
        $staff = StaffProfile::create([
            'user_id' => User::factory()->create()->id,
            'employee_code' => 'STF-LV-02',
            'is_active' => true,
        ]);

        $day = Carbon::now()->addDays(10)->toDateString();

        $leave = LeaveRequest::create([
            'staff_profile_id' => $staff->id,
            'start_date' => $day,
            'end_date' => $day,
            'reason' => 'Away',
            'status' => 'approved',
            'reviewed_by' => $manager->id,
            'reviewed_at' => now(),
        ]);

        StaffSchedule::updateOrCreate(
            [
                'staff_profile_id' => $staff->id,
                'schedule_date' => $day,
            ],
            [
                'start_time' => null,
                'end_time' => null,
                'is_day_off' => true,
                'notes' => 'Approved leave #'.$leave->id,
            ],
        );

        $this->actingAs($manager)
            ->patch(route('leave-requests.review', $leave), ['status' => 'rejected'])
            ->assertRedirect();

        $row = StaffSchedule::query()
            ->where('staff_profile_id', $staff->id)
            ->whereDate('schedule_date', $day)
            ->first();

        $this->assertNotNull($row);
        $this->assertFalse($row->is_day_off);
        $this->assertNotNull($row->start_time);
        $this->assertNotNull($row->end_time);
    }

    public function test_schedules_fill_command_creates_missing_week_rows(): void
    {
        StaffProfile::create([
            'user_id' => User::factory()->create()->id,
            'employee_code' => 'STF-FILL-01',
            'is_active' => true,
        ]);

        $this->assertSame(0, StaffSchedule::query()->count());

        Artisan::call('schedules:fill', ['--days' => 2]);

        $this->assertGreaterThanOrEqual(2, StaffSchedule::query()->count());
    }

    public function test_manager_can_fill_schedule_gaps_via_http_week(): void
    {
        $manager = $this->makeManagerUser();
        StaffProfile::create([
            'user_id' => User::factory()->create()->id,
            'employee_code' => 'STF-HTTP-WK',
            'is_active' => true,
        ]);

        $this->assertSame(0, StaffSchedule::query()->count());

        $this->actingAs($manager)
            ->from(route('schedules.index'))
            ->post(route('schedules.fill-gaps'), ['horizon' => 'week'])
            ->assertRedirect(route('schedules.index'))
            ->assertSessionHas('status');

        $this->assertGreaterThanOrEqual(7, StaffSchedule::query()->count());
    }

    public function test_manager_can_fill_schedule_gaps_via_http_month(): void
    {
        $manager = $this->makeManagerUser();
        StaffProfile::create([
            'user_id' => User::factory()->create()->id,
            'employee_code' => 'STF-HTTP-MO',
            'is_active' => true,
        ]);

        $this->assertSame(0, StaffSchedule::query()->count());

        $this->actingAs($manager)
            ->post(route('schedules.fill-gaps'), ['horizon' => 'month'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertGreaterThanOrEqual(31, StaffSchedule::query()->count());
    }

    public function test_fill_gaps_requires_valid_horizon(): void
    {
        $manager = $this->makeManagerUser();

        $this->actingAs($manager)
            ->post(route('schedules.fill-gaps'), ['horizon' => 'year'])
            ->assertSessionHasErrors('horizon');
    }

    private function makeManagerUser(): User
    {
        $role = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }
}
