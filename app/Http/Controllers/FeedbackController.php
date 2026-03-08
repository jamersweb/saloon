<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function storeStaffToCustomer(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('owner', 'manager', 'staff')) {
            abort(403);
        }

        $staffProfileId = $user->staffProfile?->id;
        if (! $staffProfileId && ! $user->hasRole('owner', 'manager')) {
            return back()->withErrors(['feedback' => 'Staff profile is required to submit feedback.']);
        }

        $payload = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        Feedback::create([
            'direction' => 'staff_to_customer',
            'staff_profile_id' => $staffProfileId,
            'customer_id' => (int) $payload['customer_id'],
            'created_by_user_id' => $user->id,
            'comment' => trim($payload['comment']),
            'reviewer_name' => $user->name,
        ]);

        return back()->with('status', 'Feedback sent to customer.');
    }

    public function storeCustomerToStaff(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('customer', 'owner', 'manager')) {
            abort(403);
        }

        $payload = $request->validate([
            'staff_profile_id' => ['required', 'exists:staff_profiles,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        Feedback::create([
            'direction' => 'customer_to_staff',
            'staff_profile_id' => (int) $payload['staff_profile_id'],
            'created_by_user_id' => $user->id,
            'rating' => (int) $payload['rating'],
            'comment' => trim($payload['comment']),
            'reviewer_name' => $user->name,
        ]);

        return back()->with('status', 'Thank you. Your review was submitted.');
    }
}

