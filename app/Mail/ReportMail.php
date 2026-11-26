<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReportMail extends Mailable
{
    /** @param array<string, array{0: string, 1: string}> $files name => [contents, mime] */
    public function __construct(public string $heading, public string $text, public array $files) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.report');
    }

    public function attachments(): array
    {
        return collect($this->files)->map(fn ($f, $name) => Attachment::fromData(fn () => $f[0], $name)->withMime($f[1]))->values()->all();
    }
}
