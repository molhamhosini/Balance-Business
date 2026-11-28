<?php

namespace App\Services\Accounting;

use App\Mail\InvoiceMail;
use App\Models\AuditLog;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/** Send a posted sales document to the customer by email or WhatsApp, as a signed link to the printable invoice. */
class InvoiceSharing
{
    public const LINK_DAYS = 90;

    public function link(Invoice $inv, string $lang = 'ar'): string
    {
        return URL::temporarySignedRoute('invoice.shared', now()->addDays(self::LINK_DAYS), ['invoice' => $inv->id] + ($lang === 'en' ? ['lang' => 'en'] : []));
    }

    public function defaultMessage(Invoice $inv, string $lang = 'ar'): string
    {
        $c = $inv->company;
        if ($lang === 'en') {
            return 'Dear '.\App\Support\InvoiceText::name($inv->party, 'en').",\n"
                .\App\Support\InvoiceText::title($inv, 'en').' no. '.$inv->number.' dated '.$inv->date->toDateString().' for '.number_format((float) $inv->total, 2).' '.($inv->currency ?: 'AED').".\n"
                ."Thank you.\n".\App\Support\InvoiceText::name($c, 'en');
        }

        return __('Dear :party,', ['party' => $inv->party?->name])."\n"
            .__(':doc no. :n dated :d for :amount :cur.', ['doc' => __(Invoice::KINDS[$inv->kind]), 'n' => $inv->number, 'd' => $inv->date->toDateString(), 'amount' => number_format((float) $inv->total, 2), 'cur' => $inv->currency ?: 'AED'])."\n"
            .__('Thank you.')."\n".($c->name_ar ?? '');
    }

    public function email(Invoice $inv, string $to, ?string $cc, string $message, string $lang = 'ar'): void
    {
        $this->guard($inv);
        $mail = Mail::to($to);
        if ($cc) {
            $mail->cc(array_filter(array_map('trim', explode(',', $cc))));
        }
        $mail->send(new InvoiceMail($inv, $message, $this->link($inv, $lang), $lang));
        AuditLog::record('emailed', $inv, null, ['to' => $to, 'cc' => $cc]);
        $this->markSent($inv);
    }

    /** wa.me link with the message and the invoice link; phone may be empty (WhatsApp then asks for the contact). */
    public function whatsappUrl(Invoice $inv, ?string $phone, string $message, string $lang = 'ar'): string
    {
        $this->guard($inv);
        AuditLog::record('whatsapp', $inv, null, ['phone' => $phone]);
        $this->markSent($inv);

        return 'https://wa.me/'.self::normalizePhone($phone).'?text='.rawurlencode($message."\n".$this->link($inv, $lang));
    }

    /** International digits for wa.me. Local UAE numbers (05x…) get the 971 prefix. */
    public static function normalizePhone(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', strtr((string) $phone, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            $d = '971'.substr($d, 1);
        }

        return $d;
    }

    private function markSent(Invoice $inv): void
    {
        if ($inv->isQuotation() && $inv->status === 'draft' && auth()->user()) {
            app(InvoiceService::class)->markQuotationSent($inv, auth()->user());
        }
    }

    private function guard(Invoice $inv): void
    {
        if (! $inv->isSendable()) {
            throw new \App\Exceptions\AccountingException(__('Only posted sales documents can be sent.'));
        }
    }
}
