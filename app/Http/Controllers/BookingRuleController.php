<?php

namespace App\Http\Controllers;

use App\Models\BookingRule;
use App\Services\StaffScheduleGeneratorService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookingRuleController extends Controller
{
    public function __construct(private readonly StaffScheduleGeneratorService $staffScheduleGenerator) {}

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'slot_interval_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'opening_time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'closing_time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'min_advance_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'public_requires_approval' => ['required', 'boolean'],
            'allow_customer_cancellation' => ['required', 'boolean'],
            'cancellation_cutoff_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'apply_to_staff_month' => ['nullable', 'boolean'],
        ]);

        $open = Carbon::parse('2000-01-01 '.$data['opening_time'].':00');
        $close = Carbon::parse('2000-01-01 '.$data['closing_time'].':00');
        if ($close->lessThanOrEqualTo($open)) {
            return back()->withErrors(['closing_time' => 'Closing time must be after opening time.'])->withInput();
        }

        $rule = BookingRule::current();
        $previousStart = $rule->defaultShiftStart();
        $previousEnd = $rule->defaultShiftEnd();
        $hoursChanged = $previousStart !== $data['opening_time'] || $previousEnd !== $data['closing_time'];
        $applyToStaffMonth = (bool) ($data['apply_to_staff_month'] ?? false);
        unset($data['apply_to_staff_month']);

        $rule->update($data);

        $refreshed = 0;
        if ($hoursChanged && $applyToStaffMonth) {
            $start = now()->startOfDay();
            $end = now()->copy()->addDays(29)->startOfDay();
            $refreshed = $this->staffScheduleGenerator->syncDefaultShiftsForActiveStaff(
                $start,
                $end,
                null,
                $previousStart,
                $previousEnd,
            );
        }

        Audit::log($request->user()?->id, 'booking_rules.updated', 'BookingRule', $rule->id, array_merge($data, [
            'staff_month_refreshed' => $refreshed,
        ]));

        $status = 'Booking rules updated.';
        if ($hoursChanged && $applyToStaffMonth) {
            $status .= " Refreshed {$refreshed} staff schedule row(s) for the next 30 days.";
        }

        return back()->with('status', $status);
    }
}
