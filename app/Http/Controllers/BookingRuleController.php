<?php

namespace App\Http\Controllers;

use App\Models\BookingRule;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookingRuleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'slot_interval_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'min_advance_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'public_requires_approval' => ['required', 'boolean'],
            'allow_customer_cancellation' => ['required', 'boolean'],
            'cancellation_cutoff_hours' => ['required', 'integer', 'min:0', 'max:168'],
        ]);

        $rule = BookingRule::current();
        $rule->update($data);

        Audit::log($request->user()?->id, 'booking_rules.updated', 'BookingRule', $rule->id, $data);

        return back()->with('status', 'Booking rules updated.');
    }
}

