<?php

namespace Tests\Feature\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Voucher;
use App\Services\Accounting\EInvoiceService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PartyService;
use App\Services\Accounting\ReportService;
use App\Support\Money;

/** Phase 2: customers & suppliers, invoices, VAT per line, allocations, aging, e-invoicing. */
class InvoicingTest extends AccountingTestCase
{
    private ?InvoiceService $svc = null;

    private function invoices(): InvoiceService { return $this->svc ??= app(InvoiceService::class); }

    private function party(string $type, array $attrs = []): Party
    {
        return app(PartyService::class)->create($this->company, $attrs + ['type' => $type, 'name_ar' => $type === 'customer' ? 'فندق الواحة' : 'مزرعة النخيل', 'payment_terms_days' => 30], $this->accountant);
    }

    private function invoice(string $kind, Party $p, array $lines, array $attrs = []): Invoice
    {
        $inv = new Invoice($attrs + ['company_id' => $this->company->id, 'branch_id' => $this->company->branches()->orderBy('id')->first()->id, 'kind' => $kind, 'payment' => 'credit', 'party_id' => $p->id, 'date' => '2026-11-05']);

        return $this->invoices()->saveDraft($inv, $lines, $this->entry);
    }

    private function line(string $acc, string $qty, string $price, ?string $tax = 'SR'): array
    {
        return ['account_id' => $this->acc($acc), 'description' => 'لحم', 'quantity' => $qty, 'unit_price' => $price, 'tax_code_id' => $tax ? $this->tax($tax) : null];
    }

    public function test_credit_sale_posts_customer_revenue_and_output_vat_per_line(): void
    {
        $c = $this->party('customer');
        $inv = $this->invoice('sale', $c, [$this->line('4111', '10.5', '33.3333'), $this->line('4113', '1', '200', 'ZR')]);
        $this->assertSame('350.00', $inv->lines[0]->net); // 10.5 × 33.3333 = 349.99965 → 350.00
        $this->assertSame('17.50', $inv->tax_total);
        $this->assertSame('567.50', $inv->total);
        $this->assertSame('2026-12-05', $inv->due_date->toDateString());

        $inv = $this->invoices()->post($inv, $this->accountant);
        $this->assertSame('INV-2026-00001', $inv->number);
        $lines = $inv->journalEntry->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('567.50', $lines['1131']->debit);
        $this->assertSame($c->id, $lines['1131']->party_id);
        $this->assertSame('350.00', $lines['4111']->credit);
        $this->assertSame('17.50', $lines['2131']->credit);
        $this->assertSame(56750, $c->balance());
        $this->assertSame('not_paid', $inv->paymentState());

        $vat = app(ReportService::class)->vat201($this->company, '2026-11-01', '2026-11-30');
        $this->assertSame(35000, $vat['1.1']['amount']);
        $this->assertSame(1750, $vat['1.1']['vat']);
        $this->assertSame(20000, $vat['4']['amount']);
    }

    public function test_quotation_is_numbered_never_posts_and_converts_to_a_draft_invoice(): void
    {
        $c = $this->party('customer');
        $q = $this->invoice('quotation', $c, [$this->line('4111', '10', '50')]);
        $this->assertSame('QT-2026-00001', $q->number);
        $this->assertSame('draft', $q->status);
        $this->assertSame('2026-12-05', $q->due_date->toDateString()); // valid 30 days
        $this->assertNull($q->journal_entry_id);
        $this->assertSame(0, $c->balance());
        $this->assertTrue($q->isSendable());

        try {
            $this->invoices()->post($q, $this->accountant);
            $this->fail('A quotation must not post.');
        } catch (\App\Exceptions\AccountingException) {
        }

        $q = $this->invoices()->markQuotationSent($q, $this->entry);
        $this->assertSame('sent', $q->status);
        $q = $this->invoices()->saveDraft($q->fresh(), [$this->line('4111', '12', '50')], $this->entry); // still editable once sent
        $this->assertSame('sent', $q->status);
        $this->assertSame('QT-2026-00001', $q->number);
        $this->assertSame('630.00', $q->total);

        $inv = $this->invoices()->convertQuotation($q, $this->entry);
        $this->assertSame('sale', $inv->kind);
        $this->assertSame('draft', $inv->status);
        $this->assertSame($q->id, $inv->quotation_id);
        $this->assertSame('630.00', $inv->total);
        $this->assertSame('converted', $q->fresh()->status);
        $this->expectException(\App\Exceptions\AccountingException::class);
        $this->invoices()->saveDraft($q->fresh(), [$this->line('4111', '1', '1')], $this->entry);
    }

    public function test_quotation_can_be_cancelled_and_not_converted_after(): void
    {
        $q = $this->invoice('quotation', $this->party('customer'), [$this->line('4111', '1', '100')]);
        $this->invoices()->cancelQuotation($q, $this->entry, 'السعر تغير');
        $this->assertSame('cancelled', $q->fresh()->status);
        $this->assertFalse($q->fresh()->isSendable());
        $this->expectException(\App\Exceptions\AccountingException::class);
        $this->invoices()->convertQuotation($q->fresh(), $this->entry);
    }

    public function test_purchase_order_never_posts_and_converts_to_a_draft_bill(): void
    {
        $s = $this->party('supplier');
        $po = $this->invoice('purchase_order', $s, [$this->line('5111', '10', '20')]);
        $this->assertSame('PO-2026-00001', $po->number);
        $this->assertTrue($po->isQuotation());
        $this->assertNull($po->supplier_invoice_number);
        $this->assertNull($po->journal_entry_id);
        $this->assertSame(0, $s->balance());
        try {
            $this->invoices()->post($po, $this->accountant);
            $this->fail('A purchase order must not post.');
        } catch (\App\Exceptions\AccountingException) {
        }
        $po = $this->invoices()->markQuotationSent($po, $this->entry);
        $bill = $this->invoices()->convertQuotation($po, $this->entry);
        $this->assertSame('purchase', $bill->kind);
        $this->assertSame('draft', $bill->status);
        $this->assertSame($po->id, $bill->quotation_id);
        $this->assertSame('210.00', $bill->total);
        $this->assertSame('converted', $po->fresh()->status);
        $bill = $this->invoices()->post($bill, $this->accountant);
        $this->assertSame('posted', $bill->status);
        $this->assertStringStartsWith('BILL-', $bill->number);

        $po2 = $this->invoice('purchase_order', $s, [$this->line('5111', '1', '5')]);
        $this->assertSame('PO-2026-00002', $po2->number);
        $this->invoices()->cancelQuotation($po2, $this->entry);
        $this->assertSame('cancelled', $po2->fresh()->status);
    }

    public function test_cash_sale_debits_the_cash_box(): void
    {
        $inv = $this->invoice('sale', $this->party('customer'), [$this->line('4113', '2', '50')], ['payment' => 'cash', 'cash_account_id' => $this->acc('1111')]);
        $inv = $this->invoices()->post($inv, $this->accountant);
        $this->assertSame('105.00', $inv->journalEntry->lines->firstWhere('account_id', $this->acc('1111'))->debit);
        $this->assertSame('paid', $inv->paymentState());
    }

    public function test_purchase_with_input_vat_and_duplicate_supplier_invoice_blocked(): void
    {
        $s = $this->party('supplier');
        $bill = $this->invoice('purchase', $s, [$this->line('5111', '1', '1000')], ['supplier_invoice_number' => 'F-77']);
        $bill = $this->invoices()->post($bill, $this->accountant);
        $this->assertSame('BILL-2026-00001', $bill->number);
        $lines = $bill->journalEntry->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('1050.00', $lines['2111']->credit);
        $this->assertSame('50.00', $lines['1161']->debit);
        $this->assertSame(-105000, $s->balance());

        $this->expectException(AccountingException::class);
        $this->invoice('purchase', $s, [$this->line('5111', '1', '10')], ['supplier_invoice_number' => 'F-77']);
    }

    public function test_purchase_invoice_number_is_automatic_and_sequential_when_left_empty(): void
    {
        $s = $this->party('supplier');
        $a = $this->invoice('purchase', $s, [$this->line('5111', '1', '100')]);
        $b = $this->invoice('purchase', $s, [$this->line('5111', '1', '200')]);
        $this->assertSame('INV-2026-10001', $a->supplier_invoice_number);
        $this->assertSame('INV-2026-10002', $b->supplier_invoice_number);
        $this->assertSame('INV-2026-10003', app(\App\Services\Accounting\SequenceService::class)->peekSupplierInvoice($this->company->id, 2026));
        $this->assertSame('INV-2026-10001', $this->invoices()->post($a, $this->accountant)->supplier_invoice_number);
        // a new year starts its own series
        $this->assertSame('INV-2027-10001', $this->invoice('purchase', $s, [$this->line('5111', '1', '50')], ['date' => '2027-01-05'])->supplier_invoice_number);
    }

    public function test_reverse_charge_purchase_self_assesses_and_fills_boxes_3_and_10(): void
    {
        $s = $this->party('supplier', ['country' => 'GB']);
        $bill = $this->invoices()->post($this->invoice('purchase', $s, [$this->line('6111', '1', '2000', 'RC')], ['supplier_invoice_number' => 'UK-1']), $this->accountant);
        $this->assertSame('2000.00', $bill->total); // supplier is paid the net only
        $vat = app(ReportService::class)->vat201($this->company, '2026-11-01', '2026-11-30');
        $this->assertSame(200000, $vat['3']['amount']);
        $this->assertSame(10000, $vat['3']['vat']);
        $this->assertSame(200000, $vat['10']['amount']);
        $this->assertSame(10000, $vat['10']['vat']);
        $this->assertSame(0, $vat['14']['vat']);
    }

    public function test_credit_limit_warns_or_blocks(): void
    {
        $warn = $this->party('customer', ['credit_limit' => '100', 'credit_limit_action' => 'warn']);
        $this->invoices()->post($this->invoice('sale', $warn, [$this->line('4111', '1', '200')]), $this->accountant);
        $this->assertNotEmpty($this->invoices()->warnings);

        $block = $this->party('customer', ['name_ar' => 'مطعم', 'credit_limit' => '100', 'credit_limit_action' => 'block']);
        $inv = $this->invoice('sale', $block, [$this->line('4111', '1', '200')]);
        try {
            $this->invoices()->post($inv, $this->accountant);
            $this->fail('limit not enforced');
        } catch (AccountingException) {
        }
        $this->assertSame('posted', $this->invoices()->post($inv, $this->chief)->status); // chief may override
    }

    public function test_credit_note_reverses_vat_and_settles_the_invoice(): void
    {
        $c = $this->party('customer');
        $inv = $this->invoices()->post($this->invoice('sale', $c, [$this->line('4111', '4', '100')]), $this->accountant);
        $cn = $this->invoices()->makeReturn($inv, $this->entry);
        $this->assertSame('4114', $cn->lines[0]->account->code);
        $cn = $this->invoices()->post($cn, $this->accountant);
        $this->assertSame('CN-2026-00001', $cn->number);
        $this->assertSame(0, $c->balance());
        $this->assertSame('paid', $inv->fresh()->paymentState());
        $vat = app(ReportService::class)->vat201($this->company, '2026-11-01', '2026-11-30');
        $this->assertSame(0, $vat['1.1']['vat']);
    }

    public function test_payment_allocation_partial_then_full_and_advance(): void
    {
        $this->postedOpening();
        $c = $this->party('customer');
        $inv = $this->invoices()->post($this->invoice('sale', $c, [$this->line('4111', '1', '1000')]), $this->accountant);
        $v = $this->invoices()->registerPayment($inv, ['date' => '2026-11-20', 'cash_account_id' => $this->acc('1114'), 'amount' => '400'], $this->entry, $this->vouchers());
        $this->assertSame('draft', $v->status); // data entry may not post vouchers: someone else posts it
        $v = $this->vouchers()->post($v, $this->accountant);
        $this->assertSame('RV-2026-00001', $v->number);
        $this->assertSame($c->id, $v->journalEntry->lines->firstWhere('account_id', $this->acc('1131'))->party_id);
        $this->assertSame('partial', $inv->fresh()->paymentState());
        $this->assertSame(65000, $inv->fresh()->openCents());

        // Receipt larger than the invoice: the rest stays as an advance (unapplied) for the customer.
        $v2 = $this->vouchers()->post($this->voucher(['type' => 'receipt', 'party_id' => $c->id, 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('4111'), 'amount' => '1000', 'tax_code_id' => $this->tax('OS')]), $this->accountant);
        $this->assertSame($this->acc('1131'), $v2->counter_account_id, 'party forces its control account');
        $this->invoices()->allocate($v2, [$inv->id => '650'], $this->accountant);
        $this->assertSame('paid', $inv->fresh()->paymentState());
        $this->assertSame(35000, $this->invoices()->unallocatedCents($v2));
        $this->assertSame(-35000, $c->balance());

        $aging = app(ReportService::class)->aging($this->company, 'customer', '2026-11-30');
        $this->assertSame(-35000, $aging['totals']['unapplied']);
        $this->assertSame(-35000, $aging['totals']['total']);

        // Over-allocation rejected
        $inv2 = $this->invoices()->post($this->invoice('sale', $c, [$this->line('4111', '1', '100')]), $this->accountant);
        $this->expectException(AccountingException::class);
        $this->invoices()->allocate($v2, [$inv2->id => '400'], $this->accountant);
    }

    public function test_voucher_settles_the_invoices_chosen_on_it_when_posted(): void
    {
        $this->postedOpening();
        $c = $this->party('customer');
        $a = $this->invoices()->post($this->invoice('sale', $c, [$this->line('4111', '1', '1000')]), $this->accountant); // 1,050.00
        $b = $this->invoices()->post($this->invoice('sale', $c, [$this->line('4111', '1', '200')]), $this->accountant);  // 210.00
        $this->assertSame([$a->id, $b->id], $this->invoices()->openInvoicesFor($c->id, 'receipt')->pluck('id')->all());
        $this->assertCount(0, $this->invoices()->openInvoicesFor($c->id, 'payment'));

        $v = $this->voucher(['type' => 'receipt', 'party_id' => $c->id, 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('1131'), 'amount' => '1160', 'tax_code_id' => $this->tax('OS'),
            'planned_allocations' => [['invoice_id' => $a->id, 'amount' => '1050'], ['invoice_id' => $b->id, 'amount' => '110']]]);
        $this->assertSame('draft', $v->status);
        $this->assertSame('not_paid', $a->fresh()->paymentState(), 'nothing is settled before posting');

        $this->vouchers()->post($v, $this->accountant);
        $this->assertSame('paid', $a->fresh()->paymentState());
        $this->assertSame('partial', $b->fresh()->paymentState());
        $this->assertSame(10000, $b->fresh()->openCents());
        $this->assertSame(0, $this->invoices()->unallocatedCents($v->fresh()));

        // More than the open balance, or more than the voucher, is rejected on save.
        $this->expectException(AccountingException::class);
        $this->voucher(['type' => 'receipt', 'party_id' => $c->id, 'cash_account_id' => $this->acc('1114'), 'counter_account_id' => $this->acc('1131'), 'amount' => '50', 'tax_code_id' => $this->tax('OS'),
            'planned_allocations' => [['invoice_id' => $b->id, 'amount' => '100']]]);
    }

    public function test_aging_buckets_by_due_date_and_statement(): void
    {
        $c = $this->party('customer', ['payment_terms_days' => 0]);
        $this->invoices()->post($this->invoice('sale', $c, [$this->line('4111', '1', '100', null)], ['date' => '2026-08-01']), $this->accountant);
        $this->invoices()->post($this->invoice('sale', $c, [$this->line('4111', '1', '200', null)], ['date' => '2026-11-20']), $this->accountant);
        $a = app(ReportService::class)->aging($this->company, 'customer', '2026-11-30');
        $this->assertSame(10000, $a['rows'][0]['90+']);
        $this->assertSame(20000, $a['rows'][0]['8-15']);
        $st = app(ReportService::class)->partyStatement($c, '2026-11-01', '2026-11-30');
        $this->assertSame(10000, $st['opening']);
        $this->assertSame(30000, $st['closing']);
    }

    public function test_posted_invoice_locked_and_einvoiced_cannot_be_cancelled(): void
    {
        $inv = $this->invoices()->post($this->invoice('sale', $this->party('customer', ['trn' => '100000000000003']), [$this->line('4111', '1', '100')]), $this->accountant);
        try {
            $inv->update(['total' => 1]);
            $this->fail('edit allowed');
        } catch (AccountingException) {
        }
        $xml = app(EInvoiceService::class)->toUbl($inv);
        $this->assertStringContainsString('<cbc:ID>INV-2026-00001</cbc:ID>', $xml);
        $this->assertStringContainsString('100000000000003', $xml);
        $this->assertNotNull(simplexml_load_string($xml));
        $inv = $inv->fresh();
        app(EInvoiceService::class)->setStatus($inv, 'sent', $this->chief, 'ASP-1');
        $this->expectException(AccountingException::class);
        $this->invoices()->cancel($inv->fresh(), $this->chief, 'error');
    }

    public function test_cancel_reverses_entry_and_vat(): void
    {
        $c = $this->party('customer');
        $inv = $this->invoices()->post($this->invoice('sale', $c, [$this->line('4111', '1', '100')]), $this->accountant);
        $this->invoices()->cancel($inv, $this->chief, 'wrong customer');
        $this->assertSame('cancelled', $inv->fresh()->status);
        $this->assertSame(0, $c->balance());
        $this->assertSame(0, app(ReportService::class)->vat201($this->company, '2026-11-01', '2026-11-30')['1.1']['vat']);
    }

    public function test_party_codes_are_sequential_under_control_account_and_tax_class_applies(): void
    {
        $a = $this->party('customer');
        $b = $this->party('customer', ['name_ar' => 'ب', 'tax_code_id' => $this->tax('ZR')]);
        $s = $this->party('supplier');
        $this->assertSame('1131-0001', $a->code);
        $this->assertSame('1131-0002', $b->code);
        $this->assertSame('2111-0001', $s->code);
        $this->assertSame($this->tax('SR'), $a->tax_code_id);
        $inv = $this->invoice('sale', $b, [['account_id' => $this->acc('4111'), 'description' => 'x', 'quantity' => 1, 'unit_price' => 100]]);
        $this->assertSame('0.00', $inv->tax_total, 'zero-rated customer');
        $b->update(['name_ar' => 'اسم جديد', 'trn' => '100000000000003']); // names and TRNs stay editable
        $this->assertSame('اسم جديد', $b->fresh()->name_ar);
    }

    public function test_data_entry_cannot_post_invoices(): void
    {
        $inv = $this->invoice('sale', $this->party('customer'), [$this->line('4111', '1', '100')]);
        $this->expectException(AccountingException::class);
        $this->invoices()->post($inv, $this->entry);
    }
}
