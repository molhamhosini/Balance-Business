<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvoiceMail extends Mailable
{
    public function __construct(public Invoice $inv, public string $text, public string $link, public string $lang = 'ar') {}

    public function envelope(): Envelope
    {
        $c = $this->inv->company;

        return new Envelope(subject: \App\Support\InvoiceText::title($this->inv, $this->lang).' '.$this->inv->number.' — '.(\App\Support\InvoiceText::name($c, $this->lang) ?: config('app.name')));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice');
    }

    public function attachments(): array
    {
        $pdf = \App\Services\Documents\InvoiceExport::invoicePdf($this->inv, $this->lang);

        return [Attachment::fromData(fn () => $pdf, \App\Services\Documents\InvoiceExport::fileName($this->inv, 'pdf', $this->lang))->withMime('application/pdf')];
    }
}
