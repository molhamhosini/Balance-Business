<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Perm;

/**
 * E-invoicing readiness (requirements §5). Produces the structured invoice (UBL 2.1, the syntax
 * used by the UAE PINT AE specification) and JSON, and keeps the transmission status per invoice.
 * Sending happens through the accredited service provider (ASP) the company contracts; until that
 * integration exists the status is recorded by hand.
 */
class EInvoiceService
{
    public const UNTDID_TAX = ['standard' => 'S', 'zero' => 'Z', 'exempt' => 'E', 'out_of_scope' => 'O', 'reverse_charge' => 'AE'];

    public function __construct(private PostingService $posting) {}

    public function toArray(Invoice $inv): array
    {
        $inv->loadMissing(['company', 'party', 'lines.taxCode', 'branch', 'original']);
        $c = $inv->company;
        $p = $inv->party;

        return [
            'uuid' => $inv->uuid,
            'number' => $inv->number,
            'type' => $inv->kind === 'sale_return' ? '381' : '380', // UNTDID 1001: 380 invoice, 381 credit note
            'issue_date' => $inv->date->toDateString(),
            'due_date' => $inv->due_date?->toDateString(),
            'currency' => $inv->currency,
            'billing_reference' => $inv->original?->number,
            'seller' => ['name' => $c->name_ar, 'name_en' => $c->name_en, 'trn' => $c->trn, 'address' => $c->address, 'emirate' => $inv->branch->emirate, 'country' => 'AE'],
            'buyer' => ['name' => $p->name_ar, 'name_en' => $p->name_en, 'trn' => $p->trn, 'address' => $p->address, 'emirate' => $p->emirate, 'country' => $p->country],
            'lines' => $inv->lines->map(fn ($l, $i) => [
                'id' => $i + 1, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit' => $l->unit ?: 'EA',
                'unit_price' => (string) $l->unit_price, 'net' => (string) $l->net,
                'tax_category' => self::UNTDID_TAX[$l->taxCode?->type ?? 'out_of_scope'], 'tax_rate' => (string) ($l->taxCode?->rate ?? 0), 'tax' => (string) $l->tax,
            ])->values()->all(),
            'totals' => ['net' => (string) $inv->subtotal, 'tax' => (string) $inv->tax_total, 'payable' => (string) $inv->total],
        ];
    }

    public function toJson(Invoice $inv): string
    {
        $this->assertIssuable($inv);

        return json_encode($this->toArray($inv), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function toUbl(Invoice $inv): string
    {
        $this->assertIssuable($inv);
        $d = $this->toArray($inv);
        $isCredit = $d['type'] === '381';
        $root = $isCredit ? 'CreditNote' : 'Invoice';
        $ns = $isCredit ? 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2' : 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
        $x = new \XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement($root);
        $x->writeAttribute('xmlns', $ns);
        $x->writeAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $x->writeAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $cbc = fn ($n, $v, array $attrs = []) => $this->el($x, 'cbc:'.$n, $v, $attrs);
        $cbc('CustomizationID', 'urn:peppol:pint:billing-1@ae-1');
        $cbc('ProfileID', 'urn:peppol:bis:billing');
        $cbc('ID', $d['number']);
        $cbc('UUID', $d['uuid']);
        $cbc('IssueDate', $d['issue_date']);
        if (! $isCredit && $d['due_date']) {
            $cbc('DueDate', $d['due_date']);
        }
        $cbc($isCredit ? 'CreditNoteTypeCode' : 'InvoiceTypeCode', $d['type']);
        $cbc('DocumentCurrencyCode', $d['currency']);
        if ($d['billing_reference']) {
            $x->startElement('cac:BillingReference');
            $x->startElement('cac:InvoiceDocumentReference');
            $cbc('ID', $d['billing_reference']);
            $x->endElement();
            $x->endElement();
        }
        foreach (['AccountingSupplierParty' => $d['seller'], 'AccountingCustomerParty' => $d['buyer']] as $tag => $party) {
            $x->startElement('cac:'.$tag);
            $x->startElement('cac:Party');
            $x->startElement('cac:PostalAddress');
            $cbc('StreetName', $party['address']);
            $cbc('CountrySubentity', $party['emirate']);
            $x->startElement('cac:Country');
            $cbc('IdentificationCode', $party['country']);
            $x->endElement();
            $x->endElement();
            if ($party['trn']) {
                $x->startElement('cac:PartyTaxScheme');
                $cbc('CompanyID', $party['trn']);
                $x->startElement('cac:TaxScheme');
                $cbc('ID', 'VAT');
                $x->endElement();
                $x->endElement();
            }
            $x->startElement('cac:PartyLegalEntity');
            $cbc('RegistrationName', $party['name_en'] ?: $party['name']);
            $x->endElement();
            $x->endElement();
            $x->endElement();
        }
        $cur = ['currencyID' => $d['currency']];
        $x->startElement('cac:TaxTotal');
        $cbc('TaxAmount', $d['totals']['tax'], $cur);
        foreach (collect($d['lines'])->groupBy(fn ($l) => $l['tax_category'].'|'.$l['tax_rate']) as $key => $group) {
            [$cat, $rate] = explode('|', $key);
            $x->startElement('cac:TaxSubtotal');
            $cbc('TaxableAmount', number_format($group->sum(fn ($l) => (float) $l['net']), 2, '.', ''), $cur);
            $cbc('TaxAmount', number_format($cat === 'S' ? $group->sum(fn ($l) => (float) $l['tax']) : 0, 2, '.', ''), $cur);
            $x->startElement('cac:TaxCategory');
            $cbc('ID', $cat);
            $cbc('Percent', $rate);
            $x->startElement('cac:TaxScheme');
            $cbc('ID', 'VAT');
            $x->endElement();
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();
        $x->startElement('cac:LegalMonetaryTotal');
        $cbc('LineExtensionAmount', $d['totals']['net'], $cur);
        $cbc('TaxExclusiveAmount', $d['totals']['net'], $cur);
        $cbc('TaxInclusiveAmount', $d['totals']['payable'], $cur);
        $cbc('PayableAmount', $d['totals']['payable'], $cur);
        $x->endElement();
        foreach ($d['lines'] as $l) {
            $x->startElement($isCredit ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
            $cbc('ID', (string) $l['id']);
            $cbc($isCredit ? 'CreditedQuantity' : 'InvoicedQuantity', $l['quantity'], ['unitCode' => $l['unit']]);
            $cbc('LineExtensionAmount', $l['net'], $cur);
            $x->startElement('cac:Item');
            $cbc('Name', $l['description']);
            $x->startElement('cac:ClassifiedTaxCategory');
            $cbc('ID', $l['tax_category']);
            $cbc('Percent', $l['tax_rate']);
            $x->startElement('cac:TaxScheme');
            $cbc('ID', 'VAT');
            $x->endElement();
            $x->endElement();
            $x->endElement();
            $x->startElement('cac:Price');
            $cbc('PriceAmount', $l['unit_price'], $cur);
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    private function el(\XMLWriter $x, string $name, ?string $value, array $attrs = []): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $x->startElement($name);
        foreach ($attrs as $k => $v) {
            $x->writeAttribute($k, $v);
        }
        $x->text($value);
        $x->endElement();
    }

    private function assertIssuable(Invoice $inv): void
    {
        if ($inv->status !== 'posted' || ! in_array($inv->kind, ['sale', 'sale_return'], true)) {
            throw new AccountingException(__('Only posted sales invoices and credit notes are issued electronically.'));
        }
    }

    /** Record the transmission status reported by the service provider. */
    public function setStatus(Invoice $inv, string $status, User $user, ?string $reference = null, ?string $reason = null): Invoice
    {
        $this->posting->authorize($user, Perm::EINVOICE_MANAGE);
        $this->assertIssuable($inv);
        if (! isset(Invoice::EINVOICE[$status]) || $status === 'not_sent') {
            throw new AccountingException(__('Invalid status.'));
        }
        if ($status === 'rejected' && blank($reason)) {
            throw new AccountingException(__('Enter the rejection reason.'));
        }
        $before = $inv->only(['einvoice_status', 'einvoice_reference']);
        $inv->forceFill(['einvoice_status' => $status, 'einvoice_reference' => $reference ?? $inv->einvoice_reference, 'einvoice_reason' => $reason, 'einvoice_at' => now()])->save();
        AuditLog::record('einvoice', $inv, $before, $inv->only(['einvoice_status', 'einvoice_reference', 'einvoice_reason']), $reason);

        return $inv;
    }
}
