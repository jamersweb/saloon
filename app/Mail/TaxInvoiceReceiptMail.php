<?php

namespace App\Mail;

use App\Models\FinanceSetting;
use App\Models\TaxInvoice;
use App\Support\TaxReceiptPdfView;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TaxInvoiceReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TaxInvoice $invoice
    ) {
        $this->invoice->load(['items', 'customer', 'payments']);
    }

    public function envelope(): Envelope
    {
        $business = FinanceSetting::current()->business_name;

        return new Envelope(
            subject: 'Tax receipt '.$this->invoice->invoice_number.' — '.$business,
        );
    }

    public function content(): Content
    {
        $settings = FinanceSetting::current();

        return new Content(
            view: 'mail.tax-invoice-receipt',
            with: [
                'settings' => $settings,
                'invoice' => $this->invoice,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = TaxReceiptPdfView::makePdf($this->invoice);

        $name = 'receipt-'.$this->invoice->invoice_number.'.pdf';

        return [
            Attachment::fromData(fn () => $pdf->output(), $name)
                ->withMime('application/pdf'),
        ];
    }
}
