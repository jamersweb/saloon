<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerLoyaltyLedger;
use App\Models\LoyaltyProgramSetting;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Services\LoyaltyService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoyaltyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Loyalty/Index', [
            'tiers' => LoyaltyTier::query()->orderBy('min_points')->get(),
            'customers' => Customer::query()
                ->with(['loyaltyAccount.tier'])
                ->orderBy('name')
                ->limit(300)
                ->get()
                ->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'points' => $customer->loyaltyAccount?->current_points ?? 0,
                    'tier' => $customer->loyaltyAccount?->tier?->name,
                ]),
            'recentLedgers' => CustomerLoyaltyLedger::query()
                ->with(['customer:id,name', 'createdBy:id,name'])
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (CustomerLoyaltyLedger $entry) => [
                    'id' => $entry->id,
                    'customer_name' => $entry->customer?->name,
                    'points_change' => $entry->points_change,
                    'balance_after' => $entry->balance_after,
                    'reason' => $entry->reason,
                    'reference' => $entry->reference,
                    'created_by' => $entry->createdBy?->name,
                    'created_at' => $entry->created_at,
                ]),
            'rewards' => LoyaltyReward::query()->orderByDesc('is_active')->orderBy('points_cost')->get(),
            'settings' => LoyaltyProgramSetting::current(),
            'recentRedemptions' => LoyaltyRedemption::query()
                ->with(['customer:id,name', 'reward:id,name', 'redeemedBy:id,name'])
                ->latest()
                ->limit(80)
                ->get()
                ->map(fn (LoyaltyRedemption $redemption) => [
                    'id' => $redemption->id,
                    'customer_name' => $redemption->customer?->name,
                    'reward_name' => $redemption->reward?->name,
                    'points_spent' => $redemption->points_spent,
                    'quantity' => $redemption->quantity,
                    'status' => $redemption->status,
                    'redeemed_by' => $redemption->redeemedBy?->name,
                    'created_at' => $redemption->created_at,
                ]),
        ]);
    }

    public function storeTier(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('loyalty_tiers', 'name')],
            'min_points' => ['required', 'integer', 'min:0'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'earn_multiplier' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tier = LoyaltyTier::create([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Audit::log($request->user()?->id, 'loyalty.tier_created', 'LoyaltyTier', $tier->id);

        return back()->with('status', 'Loyalty tier created.');
    }

    public function updateTier(Request $request, LoyaltyTier $tier): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('loyalty_tiers', 'name')->ignore($tier->id)],
            'min_points' => ['required', 'integer', 'min:0'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'earn_multiplier' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tier->update([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        Audit::log($request->user()?->id, 'loyalty.tier_updated', 'LoyaltyTier', $tier->id);

        return back()->with('status', 'Loyalty tier updated.');
    }

    public function storeLedger(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'points_change' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        if ((int) $data['points_change'] === 0) {
            return back()->withErrors(['points_change' => 'Points change cannot be zero.']);
        }

        $ledger = app(LoyaltyService::class)->applyPoints(
            customerId: (int) $data['customer_id'],
            pointsChange: (int) $data['points_change'],
            reason: $data['reason'],
            reference: $data['reference'] ?? null,
            createdBy: $request->user()?->id,
            notes: $data['notes'] ?? null
        );

        Audit::log($request->user()?->id, 'loyalty.points_changed', 'Customer', (int) $data['customer_id'], [
            'points_change' => (int) $data['points_change'],
            'balance_after' => $ledger->balance_after,
        ]);

        return back()->with('status', 'Loyalty points updated.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'auto_earn_enabled' => ['required', 'boolean'],
            'points_per_currency' => ['required', 'numeric', 'min:0', 'max:100'],
            'points_per_visit' => ['required', 'integer', 'min:0', 'max:1000'],
            'birthday_bonus_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'referral_bonus_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'review_bonus_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'minimum_spend' => ['required', 'numeric', 'min:0', 'max:100000'],
            'rounding_mode' => ['required', Rule::in(['floor', 'round', 'ceil'])],
        ]);

        $settings = LoyaltyProgramSetting::current();
        $settings->update($data);

        Audit::log($request->user()?->id, 'loyalty.settings_updated', 'LoyaltyProgramSetting', $settings->id, $data);

        return back()->with('status', 'Loyalty auto-earn settings updated.');
    }

    public function awardBonus(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'bonus_type' => ['required', Rule::in(['referral', 'review', 'birthday'])],
        ]);

        $awarded = app(LoyaltyService::class)->awardConfiguredBonus(
            customerId: (int) $data['customer_id'],
            bonusType: $data['bonus_type'],
            createdBy: $request->user()?->id
        );

        if (! $awarded) {
            return back()->withErrors(['bonus_type' => 'Configured points for this bonus type is zero.']);
        }

        Audit::log($request->user()?->id, 'loyalty.bonus_awarded', 'Customer', (int) $data['customer_id'], ['bonus_type' => $data['bonus_type']]);

        return back()->with('status', 'Bonus points awarded.');
    }

    public function storeReward(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $reward = LoyaltyReward::create([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Audit::log($request->user()?->id, 'loyalty.reward_created', 'LoyaltyReward', $reward->id);

        return back()->with('status', 'Loyalty reward created.');
    }

    public function updateReward(Request $request, LoyaltyReward $reward): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $reward->update([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        Audit::log($request->user()?->id, 'loyalty.reward_updated', 'LoyaltyReward', $reward->id);

        return back()->with('status', 'Loyalty reward updated.');
    }

    public function redeem(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'loyalty_reward_id' => ['required', 'exists:loyalty_rewards,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $reward = LoyaltyReward::findOrFail($data['loyalty_reward_id']);
        if (! $reward->is_active) {
            throw ValidationException::withMessages(['loyalty_reward_id' => 'Reward is inactive.']);
        }

        $quantity = (int) $data['quantity'];
        $totalCost = $reward->points_cost * $quantity;

        $account = CustomerLoyaltyAccount::query()->firstOrCreate(
            ['customer_id' => $data['customer_id']],
            ['current_points' => 0]
        );

        if ($account->current_points < $totalCost) {
            throw ValidationException::withMessages(['customer_id' => 'Insufficient loyalty points for redemption.']);
        }

        if ($reward->stock_quantity !== null && $reward->stock_quantity < $quantity) {
            throw ValidationException::withMessages(['quantity' => 'Not enough reward stock available.']);
        }

        DB::transaction(function () use ($request, $data, $reward, $quantity, $totalCost, $account): void {
            $nextBalance = $account->current_points - $totalCost;

            $tier = LoyaltyTier::query()
                ->where('is_active', true)
                ->where('min_points', '<=', $nextBalance)
                ->orderByDesc('min_points')
                ->first();

            $account->update([
                'current_points' => $nextBalance,
                'loyalty_tier_id' => $tier?->id,
                'last_activity_at' => now(),
            ]);

            LoyaltyRedemption::create([
                'customer_id' => (int) $data['customer_id'],
                'loyalty_reward_id' => $reward->id,
                'points_spent' => $totalCost,
                'quantity' => $quantity,
                'status' => 'redeemed',
                'redeemed_by' => $request->user()?->id,
            ]);

            CustomerLoyaltyLedger::create([
                'customer_id' => (int) $data['customer_id'],
                'loyalty_tier_id' => $tier?->id,
                'points_change' => -$totalCost,
                'balance_after' => $nextBalance,
                'reason' => 'Reward redemption: ' . $reward->name,
                'reference' => 'REWARD-' . $reward->id,
                'created_by' => $request->user()?->id,
            ]);

            if ($reward->stock_quantity !== null) {
                $reward->decrement('stock_quantity', $quantity);
            }

            Audit::log($request->user()?->id, 'loyalty.redeemed', 'Customer', (int) $data['customer_id'], [
                'reward_id' => $reward->id,
                'points_spent' => $totalCost,
            ]);
        });

        return back()->with('status', 'Reward redeemed successfully.');
    }
}
