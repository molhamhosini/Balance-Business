<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvoiceMail extends Mailable
{
    public function __construct(public Invoice $inv, public string $text, public string $link) {}

    public function envelope(): Envelope
    {
        $c = $this->inv->company;

        return new Envelope(subject: __(Invoice::KINDS[$this->inv->kind]).' '.$this->inv->number.' — '.($c->name_ar ?? config('app.name')));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice');
    }

    public function attachments(): array
    {
        $html = view('print.invoice', ['inv' => $this->inv->load(['company', 'branch', 'party', 'lines.taxCode', 'original'])])->render();

        return [Attachment::fromData(fn () => $html, $this->inv->number.'.html')->withMime('text/html')];
    }
}
