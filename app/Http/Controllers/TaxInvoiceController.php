<?php

namespace App\Http\Controllers;

use App\Mail\TaxInvoiceReceiptMail;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\FinanceSetting;
use App\Models\GiftCard;
use App\Models\InventoryItem;
use App\Models\InvoicePayment;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use App\Services\AppointmentVisitService;
use App\Services\InvoiceAdjustmentService;
use App\Services\TaxInvoiceDraftFromAppointmentService;
use App\Services\TaxInvoiceFinalizeService;
use App\Services\TaxInvoiceLineCalculator;
use App\Services\TaxInvoicePaymentService;
use App\Support\Audit;
use App\Support\FinanceStructure;
use App\Support\TaxReceiptPdfView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TaxInvoiceController extends Controller
{
    public function __construct(private readonly AppointmentVisitService $appointmentVisitService) {}

    protected function authorizeInvoiceAccess(Request $request, TaxInvoice $invoice): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ($user->hasRole('owner', 'manager')) {
            return;
        }

        if ($user->hasPermission('can_manage_finance')) {
            return;
        }

        if ($user->hasPermission('can_collect_payments') && ($invoice->appointment_id !== null || $this->isRetailOnlyInvoice($invoice))) {
            return;
        }

        abort(403);
    }

    protected function authorizeInvoiceCreate(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ($user->hasRole('owner', 'manager')) {
            return;
        }

        if ($user->hasPermission('can_manage_finance') || $user->hasPermission('can_collect_payments')) {
            return;
        }

        abort(403);
    }

    protected function canManageFullFinance(Request $request): bool
    {
        $user = $request->user();

        return $user && ($user->hasRole('owner', 'manager') || $user->hasPermission('can_manage_finance'));
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                TaxInvoice::STATUS_DRAFT,
                TaxInvoice::STATUS_FINALIZED,
                TaxInvoice::STATUS_VOID,
            ])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $invoices = TaxInvoice::query()
            ->with(['customer:id,name', 'payments'])
            ->when($filters['q'] ?? null, function ($query, string $term): void {
                $query->where(function ($inner) use ($term): void {
                    $inner
                        ->where('invoice_number', 'like', "%{$term}%")
                        ->orWhere('customer_display_name', 'like', "%{$term}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($term): void {
                            $customerQuery
                                ->where('name', 'like', "%{$term}%")
                                ->orWhere('phone', 'like', "%{$term}%");
                        });
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('issued_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('issued_at', '<=', $date))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(function (TaxInvoice $invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_display_name' => $invoice->customer_display_name,
                    'status' => $invoice->status,
                    'total' => (float) $invoice->total,
                    'amount_paid' => $invoice->amountPaid(),
                    'balance' => $invoice->balanceDue(),
                    'issued_at' => optional($invoice->issued_at)?->toIso8601String(),
                    'created_at' => $invoice->created_at->toIso8601String(),
                ];
            });

        return Inertia::render('Finance/Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        $this->authorizeInvoiceCreate($request);

        $settings = FinanceSetting::current();
        $saleType = $request->query('sale_type') === 'retail' || ! $this->canManageFullFinance($request)
            ? 'retail'
            : 'standard';

        return Inertia::render('Finance/Invoices/Create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone']),
            'services' => SalonService::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'category'])
                ->map(fn (SalonService $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'price' => $service->price,
                    'cost_center' => FinanceStructure::inferCostCenterFromService($service),
                ]),
            'staff_profiles' => $this->staffProfileOptions(),
            'inventory_items' => InventoryItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'selling_price']),
            'revenue_categories' => FinanceStructure::revenueCategories(),
            'cost_centers' => FinanceStructure::costCenters(),
            'appointments' => Appointment::query()
                ->with(['customer:id,name', 'service:id,name'])
                ->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_COMPLETED])
                ->latest('scheduled_start')
                ->limit(40)
                ->get()
                ->map(fn (Appointment $a) => $this->serializeInvoiceAppointmentOption($a)),
            'vat_rate_percent' => (float) $settings->vat_rate_percent,
            'currency_code' => $settings->currency_code,
            'sale_type' => $saleType,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeInvoiceCreate($request);

        $settings = FinanceSetting::current();
        $vatRate = (float) $settings->vat_rate_percent;

        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_display_name' => ['required', 'string', 'max:255'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'cashier_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.salon_service_id' => ['nullable', 'exists:salon_services,id'],
            'items.*.inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
            'items.*.staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'items.*.revenue_category' => ['nullable', Rule::in(array_keys(FinanceStructure::revenueCategories()))],
            'items.*.cost_center' => ['nullable', Rule::in(array_keys(FinanceStructure::costCenters()))],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $items = $this->normalizeInvoiceItems($data['items']);
        $this->validateInvoiceWorkflow($request, isset($data['appointment_id']) ? (int) $data['appointment_id'] : null, $items);

        $invoice = DB::transaction(function () use ($data, $items, $request, $vatRate) {
            $invoice = TaxInvoice::query()->create([
                'customer_id' => $data['customer_id'] ?? null,
                'customer_display_name' => $data['customer_display_name'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'status' => TaxInvoice::STATUS_DRAFT,
                'cashier_name' => $data['cashier_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
            ]);

            foreach ($items as $row) {
                $computed = TaxInvoiceLineCalculator::compute(
                    (float) $row['quantity'],
                    (float) $row['unit_price'],
                    $vatRate,
                    (float) ($row['discount_amount'] ?? 0)
                );
                TaxInvoiceItem::query()->create([
                    'tax_invoice_id' => $invoice->id,
                    'salon_service_id' => $row['salon_service_id'] ?? null,
                    'inventory_item_id' => $row['inventory_item_id'] ?? null,
                    'revenue_category' => $row['revenue_category'],
                    'cost_center' => $row['cost_center'],
                    'staff_profile_id' => $row['staff_profile_id'] ?? null,
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'discount_amount' => $row['discount_amount'] ?? 0,
                    'line_subtotal' => $computed['line_subtotal'],
                    'tax_rate_percent' => $computed['tax_rate_percent'],
                    'line_tax' => $computed['line_tax'],
                    'line_total' => $computed['line_total'],
                ]);
            }

            $this->recalculateTotals($invoice);

            return $invoice;
        });

        Audit::log($request->user()->id, 'finance.invoice.draft_created', 'TaxInvoice', $invoice->id, []);

        return redirect()->route('finance.invoices.show', $invoice)->with('status', 'Draft invoice created.');
    }

    public function show(Request $request, TaxInvoice $invoice): InertiaResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'reception');
        $this->authorizeInvoiceAccess($request, $invoice);

        $invoice = $this->refreshDraftIfMissingVisitItems($invoice, $request);

        $invoice->load(['items.salonService:id,name', 'items.inventoryItem:id,name,sku', 'items.staffProfile.user:id,name', 'customer.membershipCards.type:id,name', 'customer:id,name,phone,email', 'appointment.service:id,name', 'payments.createdBy:id,name']);
        $invoice->load(['adjustments' => fn ($query) => $query->orderByDesc('issued_at')]);

        $settings = FinanceSetting::current();

        $appointments = $invoice->isEditable()
            ? Appointment::query()
                ->with(['customer:id,name', 'service:id,name'])
                ->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_COMPLETED])
                ->latest('scheduled_start')
                ->limit(40)
                ->get()
                ->map(fn (Appointment $a) => $this->serializeInvoiceAppointmentOption($a))
                ->values()
                ->all()
            : [];

        $giftCardsForPayment = ($invoice->status !== TaxInvoice::STATUS_VOID)
            && ($invoice->isEditable() || $invoice->balanceDue() > 0.009)
                ? $this->eligibleGiftCardsForPayment($invoice, $request->user()?->id)
                    ->map(fn (GiftCard $card) => [
                        'id' => $card->id,
                        'code' => $card->code,
                        'remaining_value' => (float) $card->remaining_value,
                    ])
                    ->values()
                    ->all()
                : [];

        return Inertia::render('Finance/Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
                'customer_display_name' => $invoice->customer_display_name,
                'customer_email' => $invoice->customer?->email,
                'status' => $invoice->status,
                'appointment_id' => $invoice->appointment_id,
                'subtotal' => (float) $invoice->subtotal,
                'vat_amount' => (float) $invoice->vat_amount,
                'total' => (float) $invoice->total,
                'notes' => $invoice->notes,
                'adjustment_type' => $invoice->adjustment_type,
                'adjustment_reason' => $invoice->adjustment_reason,
                'related_invoice_id' => $invoice->related_invoice_id,
                'issued_at' => optional($invoice->issued_at)?->toIso8601String(),
                'cashier_name' => $invoice->cashier_name,
                'amount_paid' => $invoice->amountPaid(),
                'balance' => $invoice->balanceDue(),
                'items' => $invoice->items->map(fn (TaxInvoiceItem $item) => [
                    'id' => $item->id,
                    'salon_service_id' => $item->salon_service_id,
                    'inventory_item_id' => $item->inventory_item_id,
                    'revenue_category' => $item->revenue_category,
                    'cost_center' => $item->cost_center,
                    'staff_profile_id' => $item->staff_profile_id,
                    'staff_name' => $item->staffProfile?->user?->name,
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_amount' => (float) $item->discount_amount,
                    'line_subtotal' => (float) $item->line_subtotal,
                    'tax_rate_percent' => (float) $item->tax_rate_percent,
                    'line_tax' => (float) $item->line_tax,
                    'line_total' => (float) $item->line_total,
                ]),
                'payments' => $invoice->payments->map(fn (InvoicePayment $p) => [
                    'id' => $p->id,
                    'amount' => (float) $p->amount,
                    'method' => $p->method,
                    'method_label' => InvoicePayment::methodLabels()[$p->method] ?? ucfirst(str_replace('_', ' ', $p->method)),
                    'paid_at' => $p->paid_at->toIso8601String(),
                    'reference_note' => $p->reference_note,
                    'created_by_name' => $p->createdBy?->name,
                ]),
                'settlement_label' => $this->resolveSettlementLabel($invoice),
                'adjustments' => $invoice->adjustments->map(fn (TaxInvoice $adjustment) => [
                    'id' => $adjustment->id,
                    'invoice_number' => $adjustment->invoice_number,
                    'total' => (float) $adjustment->total,
                    'issued_at' => optional($adjustment->issued_at)?->toIso8601String(),
                    'adjustment_reason' => $adjustment->adjustment_reason,
                ])->values()->all(),
            ],
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone']),
            'services' => SalonService::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'category'])
                ->map(fn (SalonService $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'price' => $service->price,
                    'cost_center' => FinanceStructure::inferCostCenterFromService($service),
                ]),
            'staff_profiles' => $this->staffProfileOptions(),
            'inventory_items' => InventoryItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'selling_price']),
            'revenue_categories' => FinanceStructure::revenueCategories(),
            'cost_centers' => FinanceStructure::costCenters(),
            'vat_rate_percent' => (float) $settings->vat_rate_percent,
            'currency_code' => $settings->currency_code,
            'payment_methods' => InvoicePayment::methodLabels(),
            'appointments' => $appointments,
            'gift_cards_for_payment' => $giftCardsForPayment,
            'can_manage_full_finance' => $this->canManageFullFinance($request),
        ]);
    }

    public function update(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'reception');
        $this->authorizeInvoiceAccess($request, $invoice);

        if (! $invoice->isEditable()) {
            return back()->withErrors(['invoice' => 'Only draft invoices can be edited.']);
        }

        $settings = FinanceSetting::current();
        $vatRate = (float) $settings->vat_rate_percent;

        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_display_name' => ['required', 'string', 'max:255'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'cashier_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.salon_service_id' => ['nullable', 'exists:salon_services,id'],
            'items.*.inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
            'items.*.staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'items.*.revenue_category' => ['nullable', Rule::in(array_keys(FinanceStructure::revenueCategories()))],
            'items.*.cost_center' => ['nullable', Rule::in(array_keys(FinanceStructure::costCenters()))],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $items = $this->normalizeInvoiceItems($data['items']);
        $this->validateInvoiceWorkflow($request, isset($data['appointment_id']) ? (int) $data['appointment_id'] : null, $items);

        DB::transaction(function () use ($invoice, $data, $items, $vatRate) {
            $invoice->update([
                'customer_id' => $data['customer_id'] ?? null,
                'customer_display_name' => $data['customer_display_name'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'cashier_name' => $data['cashier_name'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $invoice->items()->delete();

            foreach ($items as $row) {
                $computed = TaxInvoiceLineCalculator::compute(
                    (float) $row['quantity'],
                    (float) $row['unit_price'],
                    $vatRate,
                    (float) ($row['discount_amount'] ?? 0)
                );
                TaxInvoiceItem::query()->create([
                    'tax_invoice_id' => $invoice->id,
                    'salon_service_id' => $row['salon_service_id'] ?? null,
                    'inventory_item_id' => $row['inventory_item_id'] ?? null,
                    'revenue_category' => $row['revenue_category'],
                    'cost_center' => $row['cost_center'],
                    'staff_profile_id' => $row['staff_profile_id'] ?? null,
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'discount_amount' => $row['discount_amount'] ?? 0,
                    'line_subtotal' => $computed['line_subtotal'],
                    'tax_rate_percent' => $computed['tax_rate_percent'],
                    'line_tax' => $computed['line_tax'],
                    'line_total' => $computed['line_total'],
                ]);
            }

            $this->recalculateTotals($invoice);
        });

        Audit::log($request->user()->id, 'finance.invoice.updated', 'TaxInvoice', $invoice->id, []);

        return back()->with('status', 'Invoice updated.');
    }

    public function destroy(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if (! $invoice->isEditable()) {
            return back()->withErrors(['invoice' => 'Only draft invoices can be deleted.']);
        }

        $id = $invoice->id;
        $invoice->delete();

        Audit::log($request->user()->id, 'finance.invoice.deleted', 'TaxInvoice', $id, []);

        return redirect()->route('finance.invoices.index')->with('status', 'Draft invoice deleted.');
    }

    public function finalize(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'reception');
        $this->authorizeInvoiceAccess($request, $invoice);

        if (! $invoice->isEditable()) {
            return back()->withErrors(['invoice' => 'Invoice is already finalized or void.']);
        }

        $invoice->loadMissing('items');
        $this->validateInvoiceWorkflow(
            $request,
            $invoice->appointment_id ? (int) $invoice->appointment_id : null,
            $invoice->items->map(fn (TaxInvoiceItem $item) => $item->toArray())->all()
        );

        app(TaxInvoiceFinalizeService::class)->finalize($invoice, $request->user()->id);

        Audit::log($request->user()->id, 'finance.invoice.finalized', 'TaxInvoice', $invoice->id, [
            'invoice_number' => $invoice->fresh()->invoice_number,
        ]);

        return back()->with('status', 'Tax invoice issued: '.$invoice->fresh()->invoice_number);
    }

    public function voidInvoice(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($invoice->status !== TaxInvoice::STATUS_FINALIZED) {
            return back()->withErrors(['invoice' => 'Only finalized invoices can be voided.']);
        }

        if ($invoice->amountPaid() > 0.009) {
            return back()->withErrors(['invoice' => 'Voiding is blocked while payments exist.']);
        }

        $invoice->update(['status' => TaxInvoice::STATUS_VOID]);

        Audit::log($request->user()->id, 'finance.invoice.voided', 'TaxInvoice', $invoice->id, []);

        return back()->with('status', 'Invoice voided.');
    }

    public function refundAdjustment(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $adjustment = app(InvoiceAdjustmentService::class)->createRefundAdjustment(
            $invoice,
            (float) $data['amount'],
            trim((string) $data['reason']),
            $request->user()?->id,
        );

        Audit::log($request->user()->id, 'finance.invoice.refund_adjustment', 'TaxInvoice', $invoice->id, [
            'adjustment_invoice_id' => $adjustment->id,
            'amount' => (float) $data['amount'],
        ]);

        return back()->with('status', 'Refund / adjustment recorded as '.$adjustment->invoice_number.'.');
    }

    public function storePayment(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'reception');
        $this->authorizeInvoiceAccess($request, $invoice);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(array_keys(InvoicePayment::methodLabels()))],
            'paid_at' => ['required', 'date'],
            'reference_note' => ['nullable', 'string', 'max:255'],
            'gift_card_id' => ['nullable', 'exists:gift_cards,id'],
        ]);

        $invoice->refresh();

        $paymentService = app(TaxInvoicePaymentService::class);
        if (($data['method'] ?? null) !== InvoicePayment::METHOD_GIFT_CARD) {
            $paymentService->applyAutoVoucher($invoice, $request->user());
            $invoice->refresh();
            $remainingBalance = $invoice->balanceDue();
            if ($remainingBalance <= 0.009) {
                return back()->with('status', 'Gift voucher applied and invoice paid.');
            }
            if ((float) $data['amount'] > $remainingBalance) {
                $data['amount'] = $remainingBalance;
            }
        }

        if (($data['method'] ?? null) === InvoicePayment::METHOD_GIFT_CARD && empty($data['gift_card_id'])) {
            $eligibleGiftCards = $this->eligibleGiftCardsForPayment($invoice, $request->user()?->id);

            if ($eligibleGiftCards->count() === 1) {
                $data['gift_card_id'] = (int) $eligibleGiftCards->first()->id;
            }
        }

        $paymentService->record($invoice, [
            'amount' => $data['amount'],
            'method' => $data['method'],
            'paid_at' => $data['paid_at'],
            'reference_note' => $data['reference_note'] ?? null,
            'gift_card_id' => isset($data['gift_card_id']) ? (int) $data['gift_card_id'] : null,
        ], $request->user());

        Audit::log($request->user()->id, 'finance.invoice.payment', 'TaxInvoice', $invoice->id, [
            'amount' => $data['amount'],
            'method' => $data['method'],
        ]);

        return back()->with('status', 'Payment recorded.');
    }

    public function storeBatchPayment(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'reception');
        $this->authorizeInvoiceAccess($request, $invoice);

        $data = $request->validate([
            'payments' => ['required', 'array', 'min:2'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.method' => ['required', Rule::in(array_diff(array_keys(InvoicePayment::methodLabels()), [InvoicePayment::METHOD_SPLIT_PAYMENT]))],
            'payments.*.paid_at' => ['required', 'date'],
            'payments.*.reference_note' => ['nullable', 'string', 'max:255'],
            'payments.*.gift_card_id' => ['nullable', 'exists:gift_cards,id'],
        ]);

        $rows = collect($data['payments'])
            ->map(fn (array $row) => [
                'amount' => (float) $row['amount'],
                'method' => $row['method'],
                'paid_at' => $row['paid_at'],
                'reference_note' => $row['reference_note'] ?? null,
                'gift_card_id' => isset($row['gift_card_id']) && $row['gift_card_id'] !== '' ? (int) $row['gift_card_id'] : null,
            ])
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->values()
            ->all();

        app(TaxInvoicePaymentService::class)->recordBatch($invoice, $rows, $request->user());

        Audit::log($request->user()->id, 'finance.invoice.split_payment', 'TaxInvoice', $invoice->id, [
            'rows' => count($rows),
            'amount' => round(array_sum(array_map(fn (array $row) => (float) $row['amount'], $rows)), 2),
        ]);

        return back()->with('status', 'Split payment recorded.');
    }

    public function pdf(Request $request, TaxInvoice $invoice): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'reception');
        $this->authorizeInvoiceAccess($request, $invoice);

        if ($invoice->status !== TaxInvoice::STATUS_FINALIZED || ! $invoice->invoice_number) {
            abort(404);
        }

        return TaxReceiptPdfView::makePdf($invoice)->stream('receipt-'.$invoice->invoice_number.'.pdf');
    }

    public function emailReceipt(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($invoice->status !== TaxInvoice::STATUS_FINALIZED || ! $invoice->invoice_number) {
            return back()->withErrors(['recipient_email' => 'Only finalized invoices with a receipt number can be emailed.']);
        }

        $data = $request->validate([
            'recipient_email' => ['required', 'email', 'max:255'],
        ]);

        $invoice->load(['items', 'customer', 'payments']);

        Mail::to($data['recipient_email'])->send(new TaxInvoiceReceiptMail($invoice));

        Audit::log($request->user()->id, 'finance.invoice.emailed', 'TaxInvoice', $invoice->id, [
            'recipient' => $data['recipient_email'],
        ]);

        return back()->with('status', 'Receipt emailed to '.$data['recipient_email'].'.');
    }

    private function recalculateTotals(TaxInvoice $invoice): void
    {
        $invoice->load('items');
        $invoice->update([
            'subtotal' => round($invoice->items->sum('line_subtotal'), 2),
            'vat_amount' => round($invoice->items->sum('line_tax'), 2),
            'total' => round($invoice->items->sum('line_total'), 2),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function validateInvoiceWorkflow(Request $request, ?int $appointmentId, array $items): void
    {
        $hasServiceLine = collect($items)->contains(fn (array $row): bool => $this->isServiceInvoiceRow($row));

        if ($appointmentId && ! $hasServiceLine) {
            throw ValidationException::withMessages([
                'appointment_id' => 'This invoice is linked to a visit, so it must keep at least one service line. For products only, create a Product sale without linking a visit.',
            ]);
        }

        $user = $request->user();
        if ($this->canManageFullFinance($request) || ! $user?->hasPermission('can_collect_payments') || $appointmentId) {
            return;
        }

        $hasNonRetailLine = collect($items)->contains(fn (array $row): bool => ! $this->isRetailInvoiceRow($row));
        if ($hasNonRetailLine) {
            throw ValidationException::withMessages([
                'items' => 'Direct invoices created from checkout access can contain retail product lines only. Link a visit to bill services.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isServiceInvoiceRow(array $row): bool
    {
        return filled($row['salon_service_id'] ?? null)
            || (
                empty($row['inventory_item_id'])
                && ($row['revenue_category'] ?? null) === FinanceStructure::DEFAULT_REVENUE_CATEGORY
            );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isRetailInvoiceRow(array $row): bool
    {
        return empty($row['salon_service_id'])
            && (
                filled($row['inventory_item_id'] ?? null)
                || ($row['revenue_category'] ?? null) === 'retail_product_sales'
            );
    }

    private function isRetailOnlyInvoice(TaxInvoice $invoice): bool
    {
        $invoice->loadMissing('items');

        return $invoice->items->isNotEmpty()
            && $invoice->items->every(fn (TaxInvoiceItem $item): bool => $this->isRetailInvoiceRow($item->toArray()));
    }

    private function refreshDraftIfMissingVisitItems(TaxInvoice $invoice, Request $request): TaxInvoice
    {
        if (! $invoice->isEditable() || ! $invoice->appointment_id) {
            return $invoice;
        }

        $appointment = Appointment::query()
            ->with(['service:id,name,price', 'customer:id,name'])
            ->find($invoice->appointment_id);

        if (! $appointment) {
            return $invoice;
        }

        $visitAppointments = $this->appointmentVisitService
            ->forAppointment($appointment)
            ->loadMissing([
                'service:id,name,price',
                'productUsages:id,appointment_id,inventory_item_id,quantity,notes,created_at,updated_at',
                'productUsages.item:id,name,sku,selling_price,cost_price',
            ]);

        $expectedLineCount = $visitAppointments
            ->filter(fn (Appointment $visitAppointment) => $visitAppointment->service !== null)
            ->count()
            + $visitAppointments->sum(fn (Appointment $visitAppointment) => $visitAppointment->productUsages->filter(fn ($usage) => $usage->item !== null)->count());

        $invoice->loadMissing('items');
        $actualLineCount = $invoice->items->count();

        if ($actualLineCount >= $expectedLineCount
            && (! $this->visitSourceUpdatedAfterInvoice($visitAppointments, $invoice)
                || $actualLineCount !== $expectedLineCount
                || $this->invoiceItemsMatchVisitSource($invoice->items, $visitAppointments))) {
            return $invoice;
        }

        return app(TaxInvoiceDraftFromAppointmentService::class)->create(
            $appointment,
            $request->user()?->id,
            $invoice->cashier_name ?: $request->user()?->name
        );
    }

    /**
     * @param  Collection<int, Appointment>  $visitAppointments
     */
    private function visitSourceUpdatedAfterInvoice(Collection $visitAppointments, TaxInvoice $invoice): bool
    {
        if (! $invoice->updated_at) {
            return true;
        }

        return $visitAppointments->contains(function (Appointment $visitAppointment) use ($invoice): bool {
            if ($visitAppointment->updated_at?->greaterThan($invoice->updated_at)) {
                return true;
            }

            return $visitAppointment->productUsages->contains(
                fn ($usage): bool => $usage->updated_at?->greaterThan($invoice->updated_at) ?? false
            );
        });
    }

    /**
     * @param  Collection<int, TaxInvoiceItem>  $invoiceItems
     * @param  Collection<int, Appointment>  $visitAppointments
     */
    private function invoiceItemsMatchVisitSource(Collection $invoiceItems, Collection $visitAppointments): bool
    {
        $actual = $invoiceItems
            ->map(fn (TaxInvoiceItem $item): array => $this->invoiceItemSourceSignature($item))
            ->sortBy(fn (array $signature): string => json_encode($signature) ?: '')
            ->values()
            ->all();

        $expected = $this->visitSourceInvoiceItemSignatures($visitAppointments)
            ->sortBy(fn (array $signature): string => json_encode($signature) ?: '')
            ->values()
            ->all();

        return $actual === $expected;
    }

    /**
     * @param  Collection<int, Appointment>  $visitAppointments
     * @return Collection<int, array<string, mixed>>
     */
    private function visitSourceInvoiceItemSignatures(Collection $visitAppointments): Collection
    {
        return $visitAppointments->flatMap(function (Appointment $visitAppointment): array {
            $rows = [];

            if ($visitAppointment->service) {
                $isPackageSession = (bool) $visitAppointment->customer_package_id;
                $rows[] = [
                    'kind' => 'service',
                    'salon_service_id' => (int) $visitAppointment->service_id,
                    'inventory_item_id' => null,
                    'staff_profile_id' => $visitAppointment->staff_profile_id ? (int) $visitAppointment->staff_profile_id : null,
                    'revenue_category' => $isPackageSession ? 'package_sales' : 'service_income',
                    'quantity' => $this->numericSignature(max(1, (int) ($visitAppointment->service_quantity ?? 1))),
                    'unit_price' => $this->moneySignature($isPackageSession ? 0 : ($visitAppointment->service_unit_price ?? $visitAppointment->service->price)),
                    'discount_amount' => $this->moneySignature($isPackageSession ? 0 : ($visitAppointment->service_discount_amount ?? 0)),
                ];
            }

            foreach ($visitAppointment->productUsages as $usage) {
                if (! $usage->item) {
                    continue;
                }

                $rows[] = [
                    'kind' => 'product',
                    'salon_service_id' => null,
                    'inventory_item_id' => (int) $usage->inventory_item_id,
                    'staff_profile_id' => null,
                    'revenue_category' => 'retail_product_sales',
                    'quantity' => $this->numericSignature(max(1, (int) $usage->quantity)),
                    'unit_price' => $this->moneySignature($usage->item->selling_price ?? $usage->item->cost_price ?? 0),
                    'discount_amount' => $this->moneySignature(0),
                ];
            }

            return $rows;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceItemSourceSignature(TaxInvoiceItem $item): array
    {
        return [
            'kind' => $item->inventory_item_id ? 'product' : 'service',
            'salon_service_id' => $item->salon_service_id ? (int) $item->salon_service_id : null,
            'inventory_item_id' => $item->inventory_item_id ? (int) $item->inventory_item_id : null,
            'staff_profile_id' => $item->staff_profile_id ? (int) $item->staff_profile_id : null,
            'revenue_category' => $item->revenue_category ?: ($item->inventory_item_id ? 'retail_product_sales' : 'service_income'),
            'quantity' => $this->numericSignature($item->quantity),
            'unit_price' => $this->moneySignature($item->unit_price),
            'discount_amount' => $this->moneySignature($item->discount_amount),
        ];
    }

    private function moneySignature(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function numericSignature(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInvoiceAppointmentOption(Appointment $appointment): array
    {
        $visitAppointments = $this->appointmentVisitService
            ->forAppointment($appointment)
            ->loadMissing([
                'service:id,name,price',
                'productUsages:id,appointment_id,inventory_item_id,quantity,notes',
                'productUsages.item:id,name,sku,selling_price,cost_price',
            ]);

        $serviceItems = $visitAppointments
            ->filter(fn (Appointment $item) => $item->service !== null)
            ->map(fn (Appointment $item) => [
                'salon_service_id' => $item->service_id ? (string) $item->service_id : '',
                'inventory_item_id' => '',
                'staff_profile_id' => $item->staff_profile_id ? (string) $item->staff_profile_id : '',
                'revenue_category' => $item->customer_package_id ? 'package_sales' : 'service_income',
                'cost_center' => FinanceStructure::inferCostCenterFromService($item->service),
                'description' => $item->customer_package_id
                    ? $item->service->name.' (package session)'
                    : $item->service->name,
                'quantity' => (string) max(1, (int) ($item->service_quantity ?? 1)),
                'unit_price' => (string) ($item->customer_package_id ? 0 : ($item->service_unit_price ?? $item->service->price)),
                'discount_amount' => (string) ($item->customer_package_id ? 0 : ($item->service_discount_amount ?? 0)),
            ]);

        $productItems = $visitAppointments
            ->flatMap(fn (Appointment $item) => $item->productUsages)
            ->filter(fn ($usage) => $usage->item !== null)
            ->map(function ($usage): array {
                $item = $usage->item;
                $description = $item->name;
                if (! empty($item->sku)) {
                    $description .= ' ('.$item->sku.')';
                }

                return [
                    'salon_service_id' => '',
                    'inventory_item_id' => (string) $item->id,
                    'staff_profile_id' => '',
                    'revenue_category' => 'retail_product_sales',
                    'cost_center' => FinanceStructure::DEFAULT_COST_CENTER,
                    'description' => $description,
                    'quantity' => (string) max(1, (int) $usage->quantity),
                    'unit_price' => (string) ($item->selling_price ?? $item->cost_price ?? 0),
                    'discount_amount' => '0',
                ];
            });

        $visitItems = $serviceItems->concat($productItems)->values()->all();

        return [
            'id' => $appointment->id,
            'label' => '#'.$appointment->id.' · '.($appointment->customer?->name ?? $appointment->customer_name).' · '.optional($appointment->scheduled_start)?->format('M j, H:i'),
            'customer_id' => $appointment->customer_id,
            'service_id' => $appointment->service_id,
            'visit_items' => $visitItems,
        ];
    }

    /**
     * @return list<array{id: int, name: string|null}>
     */
    private function staffProfileOptions(): array
    {
        return StaffProfile::query()
            ->with('user:id,name')
            ->where('is_active', true)
            ->assignableToServices()
            ->orderBy('employee_code')
            ->get()
            ->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'name' => $staff->user?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, GiftCard>
     */
    private function eligibleGiftCardsForPayment(TaxInvoice $invoice, ?int $issuedBy = null)
    {
        $customerId = $this->invoicePaymentCustomerId($invoice);

        if (! $customerId) {
            return collect();
        }

        return GiftCard::query()
            ->where('status', 'active')
            ->where('remaining_value', '>', 0)
            ->where('assigned_customer_id', $customerId)
            ->orderBy('code')
            ->get(['id', 'code', 'remaining_value', 'assigned_customer_id']);
    }

    private function invoicePaymentCustomerId(TaxInvoice $invoice): ?int
    {
        if ($invoice->customer_id) {
            return (int) $invoice->customer_id;
        }

        if (! $invoice->appointment_id) {
            return null;
        }

        $appointmentCustomerId = Appointment::query()
            ->whereKey($invoice->appointment_id)
            ->value('customer_id');

        return $appointmentCustomerId ? (int) $appointmentCustomerId : null;
    }

    private function resolveSettlementLabel(TaxInvoice $invoice): ?string
    {
        if ($invoice->payments->isNotEmpty()) {
            return $invoice->payments
                ->map(fn (InvoicePayment $payment) => InvoicePayment::methodLabels()[$payment->method] ?? ucfirst(str_replace('_', ' ', $payment->method)))
                ->unique()
                ->implode(', ');
        }

        $hasPackageSession = $invoice->items->contains(fn (TaxInvoiceItem $item) => str_contains(strtolower((string) $item->description), 'package session'));
        if (! $hasPackageSession) {
            return null;
        }

        $membershipCards = $invoice->customer?->membershipCards
            ?->reject(fn ($card) => $card->type?->isGiftCardType())
            ->values();
        $membershipName = $membershipCards?->firstWhere('status', 'active')?->type?->name
            ?? $membershipCards?->first()?->type?->name;

        return $membershipName ? "Package / {$membershipName}" : 'Package / Membership';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeInvoiceItems(array $rows): array
    {
        $serviceIds = collect($rows)
            ->pluck('salon_service_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $services = SalonService::query()
            ->whereIn('id', $serviceIds)
            ->get()
            ->keyBy('id');

        $inventoryIds = collect($rows)
            ->pluck('inventory_item_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $inventoryItems = InventoryItem::query()
            ->whereIn('id', $inventoryIds)
            ->get()
            ->keyBy('id');

        return collect($rows)
            ->map(function (array $row, int $index) use ($services, $inventoryItems): array {
                $serviceId = isset($row['salon_service_id']) && $row['salon_service_id'] !== ''
                    ? (int) $row['salon_service_id']
                    : null;
                $inventoryItemId = isset($row['inventory_item_id']) && $row['inventory_item_id'] !== ''
                    ? (int) $row['inventory_item_id']
                    : null;
                $service = $serviceId ? $services->get($serviceId) : null;
                $inventoryItem = $inventoryItemId ? $inventoryItems->get($inventoryItemId) : null;

                if ($inventoryItem) {
                    $quantity = (float) ($row['quantity'] ?? 0);
                    if ($quantity < 1 || floor($quantity) !== $quantity) {
                        throw ValidationException::withMessages([
                            "items.{$index}.quantity" => 'Inventory product quantities must be whole units.',
                        ]);
                    }

                    $serviceId = null;
                    $service = null;
                    $row['salon_service_id'] = null;
                    $row['inventory_item_id'] = $inventoryItem->id;
                    $row['revenue_category'] = filled($row['revenue_category'] ?? null) ? $row['revenue_category'] : 'retail_product_sales';
                    $row['cost_center'] = filled($row['cost_center'] ?? null) ? $row['cost_center'] : FinanceStructure::DEFAULT_COST_CENTER;
                } else {
                    $row['inventory_item_id'] = null;
                }

                $revenueCategory = FinanceStructure::resolveRevenueCategory(
                    $row['revenue_category'] ?? null,
                    $serviceId,
                    $row['description'] ?? null,
                );
                $costCenter = FinanceStructure::resolveInvoiceCostCenter(
                    $row['cost_center'] ?? null,
                    $service,
                    $revenueCategory,
                    $row['description'] ?? null,
                );

                if (FinanceStructure::requiresExplicitInvoiceCostCenter($row['cost_center'] ?? null, $service, $costCenter)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.cost_center" => 'Select a cost center for manual invoice lines that are not tied to a service.',
                    ]);
                }

                $row['revenue_category'] = $revenueCategory;
                $row['cost_center'] = $costCenter;

                return $row;
            })
            ->all();
    }
}
