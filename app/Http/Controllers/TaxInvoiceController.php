<?php

namespace App\Http\Controllers;

use App\Mail\TaxInvoiceReceiptMail;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\FinanceSetting;
use App\Models\InvoicePayment;
use App\Models\SalonService;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use App\Services\TaxInvoiceLineCalculator;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TaxInvoiceController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $invoices = TaxInvoice::query()
            ->with(['customer:id,name', 'payments'])
            ->latest()
            ->paginate(20)
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
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $settings = FinanceSetting::current();

        return Inertia::render('Finance/Invoices/Create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone']),
            'services' => SalonService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'price']),
            'appointments' => Appointment::query()
                ->with(['customer:id,name', 'service:id,name'])
                ->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_COMPLETED])
                ->latest('scheduled_start')
                ->limit(40)
                ->get()
                ->map(fn (Appointment $a) => [
                    'id' => $a->id,
                    'label' => '#'.$a->id.' · '.($a->customer?->name ?? $a->customer_name).' · '.optional($a->scheduled_start)?->format('M j, H:i'),
                    'customer_id' => $a->customer_id,
                    'service_id' => $a->service_id,
                ]),
            'vat_rate_percent' => (float) $settings->vat_rate_percent,
            'currency_code' => $settings->currency_code,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

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
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $invoice = DB::transaction(function () use ($data, $request, $vatRate) {
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

            foreach ($data['items'] as $row) {
                $computed = TaxInvoiceLineCalculator::compute(
                    (float) $row['quantity'],
                    (float) $row['unit_price'],
                    $vatRate
                );
                TaxInvoiceItem::query()->create([
                    'tax_invoice_id' => $invoice->id,
                    'salon_service_id' => $row['salon_service_id'] ?? null,
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
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
        $this->authorizeRoles($request, 'owner', 'manager');

        $invoice->load(['items.salonService:id,name', 'customer:id,name,phone,email', 'appointment.service:id,name', 'payments.createdBy:id,name']);

        $settings = FinanceSetting::current();

        $appointments = $invoice->isEditable()
            ? Appointment::query()
                ->with(['customer:id,name', 'service:id,name'])
                ->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_COMPLETED])
                ->latest('scheduled_start')
                ->limit(40)
                ->get()
                ->map(fn (Appointment $a) => [
                    'id' => $a->id,
                    'label' => '#'.$a->id.' · '.($a->customer?->name ?? $a->customer_name).' · '.optional($a->scheduled_start)?->format('M j, H:i'),
                    'customer_id' => $a->customer_id,
                    'service_id' => $a->service_id,
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
                'issued_at' => optional($invoice->issued_at)?->toIso8601String(),
                'cashier_name' => $invoice->cashier_name,
                'amount_paid' => $invoice->amountPaid(),
                'balance' => $invoice->balanceDue(),
                'items' => $invoice->items->map(fn (TaxInvoiceItem $item) => [
                    'id' => $item->id,
                    'salon_service_id' => $item->salon_service_id,
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'line_subtotal' => (float) $item->line_subtotal,
                    'tax_rate_percent' => (float) $item->tax_rate_percent,
                    'line_tax' => (float) $item->line_tax,
                    'line_total' => (float) $item->line_total,
                ]),
                'payments' => $invoice->payments->map(fn (InvoicePayment $p) => [
                    'id' => $p->id,
                    'amount' => (float) $p->amount,
                    'method' => $p->method,
                    'paid_at' => $p->paid_at->toIso8601String(),
                    'reference_note' => $p->reference_note,
                    'created_by_name' => $p->createdBy?->name,
                ]),
            ],
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone']),
            'services' => SalonService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'price']),
            'vat_rate_percent' => (float) $settings->vat_rate_percent,
            'currency_code' => $settings->currency_code,
            'payment_methods' => InvoicePayment::methodLabels(),
            'appointments' => $appointments,
        ]);
    }

    public function update(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

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
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        DB::transaction(function () use ($invoice, $data, $vatRate) {
            $invoice->update([
                'customer_id' => $data['customer_id'] ?? null,
                'customer_display_name' => $data['customer_display_name'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'cashier_name' => $data['cashier_name'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $invoice->items()->delete();

            foreach ($data['items'] as $row) {
                $computed = TaxInvoiceLineCalculator::compute(
                    (float) $row['quantity'],
                    (float) $row['unit_price'],
                    $vatRate
                );
                TaxInvoiceItem::query()->create([
                    'tax_invoice_id' => $invoice->id,
                    'salon_service_id' => $row['salon_service_id'] ?? null,
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
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
        $this->authorizeRoles($request, 'owner', 'manager');

        if (! $invoice->isEditable()) {
            return back()->withErrors(['invoice' => 'Invoice is already finalized or void.']);
        }

        DB::transaction(function () use ($invoice) {
            FinanceSetting::current();
            $settings = FinanceSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();

            $num = (int) $settings->next_invoice_number;
            $invoiceNumber = $settings->invoice_prefix.str_pad((string) $num, 5, '0', STR_PAD_LEFT);

            $settings->next_invoice_number = $num + 1;
            $settings->save();

            $invoice->update([
                'invoice_number' => $invoiceNumber,
                'status' => TaxInvoice::STATUS_FINALIZED,
                'issued_at' => now(),
            ]);
        });

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

    public function storePayment(Request $request, TaxInvoice $invoice): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($invoice->status !== TaxInvoice::STATUS_FINALIZED) {
            return back()->withErrors(['payment' => 'Payments are only allowed on finalized invoices.']);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(array_keys(InvoicePayment::methodLabels()))],
            'paid_at' => ['required', 'date'],
            'reference_note' => ['nullable', 'string', 'max:255'],
        ]);

        $balance = $invoice->balanceDue();
        if ((float) $data['amount'] > $balance + 0.009) {
            return back()->withErrors(['amount' => 'Amount exceeds balance due ('.number_format($balance, 2).').']);
        }

        InvoicePayment::query()->create([
            'tax_invoice_id' => $invoice->id,
            'amount' => $data['amount'],
            'method' => $data['method'],
            'paid_at' => $data['paid_at'],
            'reference_note' => $data['reference_note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        Audit::log($request->user()->id, 'finance.invoice.payment', 'TaxInvoice', $invoice->id, [
            'amount' => $data['amount'],
            'method' => $data['method'],
        ]);

        return back()->with('status', 'Payment recorded.');
    }

    public function pdf(Request $request, TaxInvoice $invoice): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($invoice->status !== TaxInvoice::STATUS_FINALIZED || ! $invoice->invoice_number) {
            abort(404);
        }

        $invoice->load(['items', 'customer', 'payments']);
        $settings = FinanceSetting::current();

        $pdf = Pdf::loadView('finance.tax_receipt_pdf', [
            'settings' => $settings,
            'invoice' => $invoice,
        ])->setPaper([0, 0, 226.77, 841.89]);

        return $pdf->stream('receipt-'.$invoice->invoice_number.'.pdf');
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
}
