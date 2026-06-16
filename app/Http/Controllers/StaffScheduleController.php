<?php

namespace App\Http\Controllers;

use App\Models\BookingRule;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Services\StaffScheduleGeneratorService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StaffScheduleController extends Controller
{
    public function __construct(private readonly StaffScheduleGeneratorService $staffScheduleGenerator) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim($request->string('search')->toString()),
            'staff_profile_id' => $request->integer('staff_profile_id') ?: null,
            'date_from' => $request->string('date_from')->toString() ?: '',
            'date_to' => $request->string('date_to')->toString() ?: '',
            'day_off' => $request->string('day_off')->toString() ?: 'all',
            'per_page' => (int) $request->integer('per_page', 30),
        ];

        if (! in_array($filters['day_off'], ['all', 'working', 'day_off'], true)) {
            $filters['day_off'] = 'all';
        }

        if (! in_array($filters['per_page'], [10, 25, 30, 50, 100], true)) {
            $filters['per_page'] = 30;
        }

        $rules = BookingRule::current();
        $today = Carbon::today();
        $visibleRangeStart = $filters['date_from'] !== ''
            ? Carbon::parse($filters['date_from'])->toDateString()
            : $today->toDateString();
        $visibleHorizonDays = max(90, (int) $rules->max_advance_days);
        $visibleRangeEnd = $filters['date_to'] !== ''
            ? Carbon::parse($filters['date_to'])->toDateString()
            : $today->copy()->addDays($visibleHorizonDays - 1)->toDateString();
        $this->staffScheduleGenerator->fillGapsForActiveStaff(
            Carbon::parse($visibleRangeStart),
            Carbon::parse($visibleRangeEnd),
            $filters['staff_profile_id'] ? [(int) $filters['staff_profile_id']] : null,
        );

        return Inertia::render('Schedules/Index', [
            'bookingRules' => [
                'slot_interval_minutes' => (int) $rules->slot_interval_minutes,
                'opening_time' => $rules->defaultShiftStart(),
                'closing_time' => $rules->defaultShiftEnd(),
                'min_advance_minutes' => (int) $rules->min_advance_minutes,
                'max_advance_days' => (int) $rules->max_advance_days,
                'public_requires_approval' => (bool) $rules->public_requires_approval,
                'allow_customer_cancellation' => (bool) $rules->allow_customer_cancellation,
                'cancellation_cutoff_hours' => (int) $rules->cancellation_cutoff_hours,
            ],
            'defaultShiftStart' => $rules->defaultShiftStart(),
            'defaultShiftEnd' => $rules->defaultShiftEnd(),
            'salonHoursLabel' => $rules->defaultShiftStart().'–'.$rules->defaultShiftEnd(),
            'staffProfiles' => StaffProfile::query()->with('user')->where('is_active', true)->orderBy('employee_code')->get()->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'employee_code' => $staff->employee_code,
                'name' => $staff->user?->name,
            ]),
            'schedules' => StaffSchedule::query()
                ->with('staffProfile.user')
                ->whereDate('schedule_date', '>=', $visibleRangeStart)
                ->whereDate('schedule_date', '<=', $visibleRangeEnd)
                ->when($filters['search'] !== '', function ($query) use ($filters): void {
                    $needle = '%' . $filters['search'] . '%';
                    $query->where(function ($scheduleQuery) use ($needle): void {
                        $scheduleQuery
                            ->where('schedule_date', 'like', $needle)
                            ->orWhereHas('staffProfile.user', fn ($userQuery) => $userQuery->where('name', 'like', $needle));
                    });
                })
                ->when($filters['staff_profile_id'], fn ($query) => $query->where('staff_profile_id', $filters['staff_profile_id']))
                ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('schedule_date', '>=', $filters['date_from']))
                ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('schedule_date', '<=', $filters['date_to']))
                ->when($filters['day_off'] === 'day_off', fn ($query) => $query->where('is_day_off', true))
                ->when($filters['day_off'] === 'working', fn ($query) => $query->where('is_day_off', false))
                ->orderBy('schedule_date')
                ->orderBy('staff_profile_id')
                ->paginate($filters['per_page'])
                ->withQueryString()
                ->through(fn (StaffSchedule $schedule) => [
                    'id' => $schedule->id,
                    'staff_profile_id' => $schedule->staff_profile_id,
                    'schedule_date' => $schedule->schedule_date,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'break_start' => $schedule->break_start,
                    'break_end' => $schedule->break_end,
                    'is_day_off' => $schedule->is_day_off,
                    'notes' => $schedule->notes,
                    'staff_name' => $schedule->staffProfile?->user?->name,
                    'staff_code' => $schedule->staffProfile?->employee_code,
                ]),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'staff_profile_id' => ['required', 'exists:staff_profiles,id'],
            'schedule_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'is_day_off' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! ($data['is_day_off'] ?? false) && ! empty($data['start_time']) && ! empty($data['end_time'])) {
            if ($message = $this->shiftSalonHoursError($data['schedule_date'], $data['start_time'], $data['end_time'])) {
                return back()->withErrors(['start_time' => $message])->withInput();
            }
        }

        $schedule = StaffSchedule::updateOrCreate(
            [
                'staff_profile_id' => $data['staff_profile_id'],
                'schedule_date' => $data['schedule_date'],
            ],
            [
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'break_start' => $data['break_start'] ?? null,
                'break_end' => $data['break_end'] ?? null,
                'is_day_off' => (bool) ($data['is_day_off'] ?? false),
                'notes' => $data['notes'] ?? null,
            ],
        );

        Audit::log($request->user()->id, 'schedule.upserted', 'StaffSchedule', $schedule->id, $schedule->toArray());

        return back()->with('status', 'Schedule saved.');
    }

    /**
     * Create missing default schedule rows for all active staff (same logic as {@see \App\Console\Commands\FillStaffSchedulesCommand}).
     */
    public function fillGaps(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'horizon' => ['required', Rule::in(['week', 'month'])],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
        ]);

        $days = $data['horizon'] === 'week' ? 7 : 30;
        $start = ! empty($data['start_date'])
            ? Carbon::parse($data['start_date'])->startOfDay()
            : Carbon::today()->startOfDay();
        $end = $start->copy()->addDays($days - 1)->startOfDay();
        $staffProfileIds = ! empty($data['staff_profile_id']) ? [(int) $data['staff_profile_id']] : null;

        $created = $this->staffScheduleGenerator->fillGapsForActiveStaff($start, $end, $staffProfileIds);

        $rangeLabel = $data['horizon'] === 'week'
            ? 'the next 7 days ('.$start->toDateString().' – '.$end->toDateString().')'
            : 'the next 30 days ('.$start->toDateString().' – '.$end->toDateString().')';

        Audit::log($request->user()->id, 'schedule.fill_gaps', 'StaffSchedule', null, [
            'horizon' => $data['horizon'],
            'days' => $days,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'staff_profile_id' => $staffProfileIds[0] ?? null,
            'rows_created' => $created,
        ]);

        return redirect()->route('schedules.index', array_filter([
            'staff_profile_id' => $staffProfileIds[0] ?? null,
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
            'day_off' => 'all',
            'per_page' => 100,
        ], fn ($value) => $value !== null && $value !== ''))->with(
            'status',
            "Added {$created} missing schedule row(s) for {$rangeLabel}. Existing rows were not changed."
        );
    }

    public function update(Request $request, StaffSchedule $schedule): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'is_day_off' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! ($data['is_day_off'] ?? false) && ! empty($data['start_time']) && ! empty($data['end_time'])) {
            if ($message = $this->shiftSalonHoursError($schedule->schedule_date->toDateString(), $data['start_time'], $data['end_time'])) {
                return back()->withErrors(['start_time' => $message])->withInput();
            }
        }

        $schedule->update([
            'start_time' => $data['start_time'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'break_start' => $data['break_start'] ?? null,
            'break_end' => $data['break_end'] ?? null,
            'is_day_off' => (bool) ($data['is_day_off'] ?? false),
            'notes' => $data['notes'] ?? null,
        ]);

        Audit::log($request->user()->id, 'schedule.updated', 'StaffSchedule', $schedule->id);

        return back()->with('status', 'Schedule updated.');
    }

    public function destroy(Request $request, StaffSchedule $schedule): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $id = $schedule->id;
        $schedule->delete();

        Audit::log($request->user()->id, 'schedule.deleted', 'StaffSchedule', $id);

        return back()->with('status', 'Schedule removed.');
    }

    private function shiftSalonHoursError(string $scheduleDate, string $start, string $end): ?string
    {
        $rules = BookingRule::current();
        $day = Carbon::parse($scheduleDate);
        $shiftStart = Carbon::parse($scheduleDate.' '.$start);
        $shiftEnd = Carbon::parse($scheduleDate.' '.$end);

        if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
            return 'Shift end must be after shift start.';
        }

        if ($shiftStart->lt($rules->salonOpenOn($day))) {
            return 'Shift starts before salon opening hours.';
        }

        if ($shiftEnd->gt($rules->salonCloseOn($day))) {
            return 'Shift ends after salon closing hours.';
        }

        return null;
    }
}
