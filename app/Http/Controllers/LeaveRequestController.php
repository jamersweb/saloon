<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\StaffProfile;
use App\Services\StaffScheduleGeneratorService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeaveRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isStaff = $user?->hasRole('staff');
        $staffProfileId = $user?->staffProfile?->id;
        $filters = [
            'staff_profile_id' => $request->integer('staff_profile_id') ?: null,
            'status' => $request->string('status')->toString() ?: 'all',
            'date_from' => $request->string('date_from')->toString() ?: '',
            'date_to' => $request->string('date_to')->toString() ?: '',
            'per_page' => (int) $request->integer('per_page', 10),
        ];

        if (! in_array($filters['status'], ['all', 'pending', 'approved', 'rejected', 'cancelled'], true)) {
            $filters['status'] = 'all';
        }

        if (! in_array($filters['per_page'], [10, 25, 50, 100], true)) {
            $filters['per_page'] = 10;
        }

        $leaveRequestsQuery = LeaveRequest::query()
            ->with(['staffProfile.user', 'reviewer'])
            ->latest();

        $staffProfilesQuery = StaffProfile::query()
            ->with('user')
            ->where('is_active', true)
            ->orderBy('employee_code');

        if ($isStaff) {
            $leaveRequestsQuery->where('staff_profile_id', $staffProfileId ?: 0);
            $staffProfilesQuery->whereKey($staffProfileId ?: 0);
            $filters['staff_profile_id'] = $staffProfileId ?: null;
        } elseif ($filters['staff_profile_id']) {
            $leaveRequestsQuery->where('staff_profile_id', $filters['staff_profile_id']);
        }

        $leaveRequestsQuery
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('start_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('end_date', '<=', $filters['date_to']));

        return Inertia::render('LeaveRequests/Index', [
            'leaveRequests' => $leaveRequestsQuery
                ->paginate($filters['per_page'])
                ->withQueryString()
                ->through(fn (LeaveRequest $leave) => [
                    'id' => $leave->id,
                    'staff_name' => $leave->staffProfile?->user?->name,
                    'start_date' => $leave->start_date,
                    'end_date' => $leave->end_date,
                    'reason' => $leave->reason,
                    'status' => $leave->status,
                    'reviewed_by' => $leave->reviewer?->name,
                ]),
            'staffProfiles' => $staffProfilesQuery->get()->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'name' => $staff->user?->name,
            ]),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string'],
        ]);

        $user = $request->user();
        $userStaffProfileId = $user?->staffProfile?->id;

        $staffProfileId = $data['staff_profile_id'] ?? $userStaffProfileId;

        if ($user?->hasRole('staff')) {
            if (! $userStaffProfileId) {
                return back()->withErrors(['staff_profile_id' => 'No staff profile linked to your account.']);
            }

            $staffProfileId = $userStaffProfileId;
        }

        if (! $staffProfileId) {
            return back()->withErrors(['staff_profile_id' => 'Staff profile is required.']);
        }

        $leaveRequest = LeaveRequest::create([
            'staff_profile_id' => $staffProfileId,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        Audit::log($request->user()->id, 'leave.created', 'LeaveRequest', $leaveRequest->id);

        return back()->with('status', 'Leave request submitted.');
    }

    public function review(Request $request, LeaveRequest $leaveRequest, StaffScheduleGeneratorService $scheduleGenerator): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'cancelled'])],
        ]);

        $previousStatus = $leaveRequest->status;

        $leaveRequest->update([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($data['status'] === 'approved' && $previousStatus !== 'approved') {
            $scheduleGenerator->applyApprovedLeave($leaveRequest->fresh());
        } elseif ($previousStatus === 'approved' && $data['status'] !== 'approved') {
            $scheduleGenerator->revokeApprovedLeaveFromCalendar($leaveRequest->fresh());
        }

        Audit::log($request->user()->id, 'leave.reviewed', 'LeaveRequest', $leaveRequest->id, ['status' => $data['status']]);

        return back()->with('status', 'Leave request updated.');
    }
}
