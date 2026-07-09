<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\RentalAgreement;
use App\Models\RentalSettlement;
use App\Services\RentalSettlementPostingService;
use App\Support\Audit;
use App\Support\FinanceStructure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RentalAgreementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        return Inertia::render('Finance/Rentals/Index', [
            'agreements' => RentalAgreement::query()
                ->with('customer:id,name,phone')
                ->withCount('settlements')
                ->orderByDesc('is_active')
                ->orderBy('partner_name')
                ->get()
                ->map(fn (RentalAgreement $agreement) => [
                    'id' => $agreement->id,
                    'customer_id' => $agreement->customer_id,
                    'customer_name' => $agreement->customer?->name,
                    'customer_phone' => $agreement->customer?->phone,
                    'partner_name' => $agreement->partner_name,
                    'agreement_type' => $agreement->agreement_type,
                    'cost_center' => $agreement->cost_center,
                    'rental_model' => $agreement->rental_model,
                    'fixed_rent_amount' => (float) $agreement->fixed_rent_amount,
                    'commission_percent' => $agreement->commission_percent !== null ? (float) $agreement->commission_percent : null,
                    'start_date' => $agreement->start_date?->toDateString(),
                    'end_date' => $agreement->end_date?->toDateString(),
                    'is_active' => (bool) $agreement->is_active,
                    'notes' => $agreement->notes,
                    'settlements_count' => $agreement->settlements_count,
                ])
                ->values()
                ->all(),
            'settlements' => RentalSettlement::query()
                ->with(['agreement:id,partner_name,agreement_type,cost_center', 'invoice:id,invoice_number,total,issued_at'])
                ->latest('settlement_date')
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (RentalSettlement $settlement) => [
                    'id' => $settlement->id,
                    'rental_agreement_id' => $settlement->rental_agreement_id,
                    'partner_name' => $settlement->agreement?->partner_name,
                    'agreement_type' => $settlement->agreement?->agreement_type,
                    'cost_center' => $settlement->agreement?->cost_center,
                    'settlement_date' => $settlement->settlement_date->toDateString(),
                    'gross_sales_amount' => $settlement->gross_sales_amount !== null ? (float) $settlement->gross_sales_amount : null,
                    'fixed_rent_amount' => (float) $settlement->fixed_rent_amount,
                    'commission_amount' => (float) $settlement->commission_amount,
                    'total_amount' => (float) $settlement->total_amount,
                    'invoice_id' => $settlement->tax_invoice_id,
                    'invoice_number' => $settlement->invoice?->invoice_number,
                    'notes' => $settlement->notes,
                ])
                ->values()
                ->all(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone']),
            'cost_centers' => FinanceStructure::costCenters(),
            'agreement_types' => [
                RentalAgreement::TYPE_CHAIR => 'Chair Rental',
                RentalAgreement::TYPE_LINE => 'Line Rental',
            ],
            'rental_models' => [
                RentalAgreement::MODEL_FIXED => 'Fixed Rent',
                RentalAgreement::MODEL_COMMISSION => 'Commission Only',
                RentalAgreement::MODEL_HYBRID => 'Fixed + Commission',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validateAgreement($request);
        $data['created_by'] = $request->user()?->id;

        $agreement = RentalAgreement::query()->create($data);

        Audit::log($request->user()?->id, 'rental_agreement.created', 'RentalAgreement', $agreement->id);

        return back()->with('status', 'Rental agreement created.');
    }

    public function update(Request $request, RentalAgreement $rentalAgreement): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $rentalAgreement->update($this->validateAgreement($request));

        Audit::log($request->user()?->id, 'rental_agreement.updated', 'RentalAgreement', $rentalAgreement->id);

        return back()->with('status', 'Rental agreement updated.');
    }

    public function destroy(Request $request, RentalAgreement $rentalAgreement): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $rentalAgreement->update(['is_active' => false]);

        Audit::log($request->user()?->id, 'rental_agreement.deactivated', 'RentalAgreement', $rentalAgreement->id);

        return back()->with('status', 'Rental agreement deactivated.');
    }

    public function settle(Request $request, RentalAgreement $rentalAgreement, RentalSettlementPostingService $postingService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'settlement_date' => ['required', 'date'],
            'gross_sales_amount' => ['nullable', 'numeric', 'min:0'],
            'fixed_rent_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $fixedRentAmount = isset($data['fixed_rent_amount']) && $data['fixed_rent_amount'] !== ''
            ? (float) $data['fixed_rent_amount']
            : (float) $rentalAgreement->fixed_rent_amount;

        $grossSalesAmount = isset($data['gross_sales_amount']) && $data['gross_sales_amount'] !== ''
            ? (float) $data['gross_sales_amount']
            : null;

        $settlement = $postingService->post(
            $rentalAgreement->loadMissing('customer'),
            $data['settlement_date'],
            $grossSalesAmount,
            $fixedRentAmount,
            $data['notes'] ?? null,
            $request->user()?->id,
        );

        Audit::log($request->user()?->id, 'rental_settlement.posted', 'RentalSettlement', $settlement->id, [
            'tax_invoice_id' => $settlement->tax_invoice_id,
        ]);

        return back()->with('status', 'Rental settlement posted to finance.');
    }

    private function validateAgreement(Request $request): array
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'partner_name' => ['required', 'string', 'max:255'],
            'agreement_type' => ['required', 'in:chair,line'],
            'cost_center' => ['required', 'in:'.implode(',', array_keys(FinanceStructure::costCenters()))],
            'rental_model' => ['required', 'in:fixed,commission,hybrid'],
            'fixed_rent_amount' => ['nullable', 'numeric', 'min:0'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['fixed_rent_amount'] = (float) ($data['fixed_rent_amount'] ?? 0);

        return $data;
    }
}
