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

    public function test_phase3_screens_render(): void
    {
        $this->postedOpening();
        $c = $this->company;
        $branch = $c->branches()->orderBy('id')->first()->id;
        $wh = \App\Models\Warehouse::where('company_id', $c->id)->first();
        $item = \App\Models\Item::create(['company_id' => $c->id, 'code' => 'P00001', 'name_ar' => 'لحم', 'unit' => 'كغ', 'sales_account_id' => $this->acc('4111'), 'inventory_account_id' => $this->acc('1141'), 'cogs_account_id' => $this->acc('5111')]);
        $item->units()->create(['name' => 'كرتونة', 'factor' => 10]);
        $inv = app(\App\Services\Accounting\InventoryService::class);
        $doc = $inv->postDocument($inv->saveDocument(new \App\Models\StockDocument(['company_id' => $c->id, 'branch_id' => $branch, 'type' => 'opening', 'date' => '2026-11-02', 'warehouse_id' => $wh->id, 'counter_account_id' => $this->acc('3111')]),
            [['item_id' => $item->id, 'quantity' => 100, 'unit_cost' => 20]], $this->entry), $this->accountant);
        $draftDoc = $inv->saveDocument(new \App\Models\StockDocument(['company_id' => $c->id, 'branch_id' => $branch, 'type' => 'count', 'date' => '2026-11-03', 'warehouse_id' => $wh->id]), [['item_id' => $item->id, 'quantity' => 98]], $this->entry);
        $banking = app(\App\Services\Accounting\BankingService::class);
        $trf = $banking->saveTransfer(new \App\Models\Transfer(['company_id' => $c->id, 'branch_id' => $branch, 'date' => '2026-11-04', 'from_account_id' => $this->acc('1114'), 'to_account_id' => $this->acc('1121'), 'amount' => '100']), $this->entry);
        $st = $banking->importStatement(\App\Models\Account::find($this->acc('1121')), [['date' => '2026-11-04', 'description' => 'deposit', 'reference' => null, 'amount' => '100.00']],
            ['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'opening_balance' => 0, 'closing_balance' => '100'], $this->chief, 'st.csv');
        $cat = \App\Models\AssetCategory::where('company_id', $c->id)->first();
        $asset = app(\App\Services\Accounting\AssetService::class)->create($c, ['branch_id' => $branch, 'asset_category_id' => $cat->id, 'name_ar' => 'سيارة', 'purchase_date' => '2026-01-10', 'cost' => '60000', 'useful_life_months' => 60], $this->chief);
        $this->actingAs($this->chief);
        $t = ['tenant' => $c];
        $urls = [
            Resources\ItemResource::getUrl('index', $t), Resources\ItemResource::getUrl('create', $t),
            Resources\ItemResource::getUrl('view', $t + ['record' => $item]), Resources\ItemResource::getUrl('edit', $t + ['record' => $item]),
            Resources\StockDocumentResource::getUrl('index', $t),
            Resources\StockDocumentResource::getUrl('create', $t + ['type' => 'opening']), Resources\StockDocumentResource::getUrl('create', $t + ['type' => 'landed_cost']),
            Resources\StockDocumentResource::getUrl('view', $t + ['record' => $doc]), Resources\StockDocumentResource::getUrl('edit', $t + ['record' => $draftDoc]),
            Resources\WarehouseResource::getUrl('index', $t), Resources\CostCenterResource::getUrl('index', $t), Resources\ExchangeRateResource::getUrl('index', $t),
            Resources\AssetCategoryResource::getUrl('index', $t),
            Resources\AssetResource::getUrl('index', $t), Resources\AssetResource::getUrl('create', $t),
            Resources\AssetResource::getUrl('view', $t + ['record' => $asset]), Resources\AssetResource::getUrl('edit', $t + ['record' => $asset]),
            Resources\TransferResource::getUrl('index', $t), Resources\TransferResource::getUrl('create', $t),
            Resources\TransferResource::getUrl('view', $t + ['record' => $trf]), Resources\TransferResource::getUrl('edit', $t + ['record' => $trf]),
            Resources\ChequeResource::getUrl('index', $t),
            Resources\BankStatementResource::getUrl('index', $t), Resources\BankStatementResource::getUrl('create', $t),
            Resources\BankStatementResource::getUrl('view', $t + ['record' => $st]),
            Pages\CostCenterReport::getUrl($t), Pages\CashFlow::getUrl($t), Pages\CashMovement::getUrl($t + ['daily' => 1]),
            Pages\StockBalance::getUrl($t), Pages\ItemCard::getUrl($t + ['item' => $item->id]), Pages\SlowMoving::getUrl($t), Pages\AssetRegister::getUrl($t),
            Pages\IncomeStatement::getUrl($t + ['cc' => \App\Models\CostCenter::where('company_id', $c->id)->value('id')]),
            Pages\DataImport::getUrl($t),
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
