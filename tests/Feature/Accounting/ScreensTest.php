<?php

namespace Tests\Feature\Accounting;

use App\Filament\Admin\Pages;
use App\Filament\Admin\Resources;

/** Every screen renders for the chief accountant, and the data-entry role is kept out of reports. */
class ScreensTest extends AccountingTestCase
{
    public function test_screens_render(): void
    {
        $this->postedOpening();
        $customer = app(\App\Services\Accounting\PartyService::class)->create($this->company, ['type' => 'customer', 'name_ar' => 'فندق', 'payment_terms_days' => 30], $this->chief);
        $svc = app(\App\Services\Accounting\InvoiceService::class);
        $line = [['account_id' => $this->acc('4111'), 'description' => 'لحم', 'quantity' => 2, 'unit_price' => 100, 'tax_code_id' => $this->tax('SR')]];
        $new = fn () => new \App\Models\Invoice(['company_id' => $this->company->id, 'branch_id' => $this->company->branches()->orderBy('id')->first()->id, 'kind' => 'sale', 'payment' => 'credit', 'party_id' => $customer->id, 'date' => '2026-11-05']);
        $inv = $svc->post($svc->saveDraft($new(), $line, $this->entry), $this->accountant);
        $this->vouchers()->post($svc->registerPayment($inv, ['date' => '2026-11-06', 'cash_account_id' => $this->acc('1114'), 'amount' => '50'], $this->entry, $this->vouchers()), $this->accountant);
        $draft = $svc->saveDraft($new(), $line, $this->entry);
        $this->actingAs($this->chief);
        $t = ['tenant' => $this->company];
        $urls = [
            Resources\JournalEntryResource::getUrl('index', $t),
            Resources\JournalEntryResource::getUrl('create', $t),
            Resources\JournalEntryResource::getUrl('view', $t + ['record' => \App\Models\JournalEntry::first()]),
            Resources\VoucherResource::getUrl('index', $t),
            Resources\VoucherResource::getUrl('create', $t),
            Resources\AccountResource::getUrl('index', $t),
            Resources\FiscalYearResource::getUrl('index', $t),
            Resources\AuditLogResource::getUrl('index', $t),
            Pages\TrialBalance::getUrl($t),
            Pages\Ledger::getUrl($t + ['account' => $this->acc('1114')]),
            Pages\JournalBook::getUrl($t),
            Pages\IncomeStatement::getUrl($t),
            Pages\BalanceSheet::getUrl($t),
            Pages\VatReturn::getUrl($t),
            Resources\CustomerResource::getUrl('index', $t),
            Resources\CustomerResource::getUrl('create', $t),
            Resources\CustomerResource::getUrl('view', $t + ['record' => $customer]),
            Resources\CustomerResource::getUrl('edit', $t + ['record' => $customer]),
            Resources\SupplierResource::getUrl('index', $t),
            Resources\SalesInvoiceResource::getUrl('index', $t),
            Resources\SalesInvoiceResource::getUrl('create', $t + ['kind' => 'sale']),
            Resources\SalesInvoiceResource::getUrl('create', $t + ['kind' => 'sale_return']),
            Resources\SalesInvoiceResource::getUrl('view', $t + ['record' => $inv]),
            Resources\SalesInvoiceResource::getUrl('edit', $t + ['record' => $draft]),
            Resources\PurchaseInvoiceResource::getUrl('index', $t),
            Resources\PurchaseInvoiceResource::getUrl('create', $t),
            Resources\VoucherResource::getUrl('view', $t + ['record' => $inv->allocations()->first()->voucher_id]),
            Pages\PartyStatement::getUrl($t + ['party' => $customer->id]),
            Pages\Aging::getUrl($t),
            Pages\Aging::getUrl($t + ['type' => 'supplier']),
            route('invoice.print', $inv),
            route('invoice.einvoice', [$inv, 'xml']),
            route('invoice.einvoice', [$inv, 'json']),
        ];
        foreach ($urls as $url) {
            $this->assertSame(200, $this->get($url)->status(), $url);
        }
    }

    public function test_data_entry_cannot_open_trial_balance(): void
    {
        $this->actingAs($this->entry);
        $this->get(Pages\TrialBalance::getUrl(['tenant' => $this->company]))->assertForbidden();
    }
}
