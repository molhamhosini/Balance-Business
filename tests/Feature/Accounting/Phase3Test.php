<?php

namespace Tests\Feature\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Party;
use App\Models\StockDocument;
use App\Models\Transfer;
use App\Models\Warehouse;
use App\Services\Accounting\AssetService;
use App\Services\Accounting\BankingService;
use App\Services\Accounting\InventoryService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PartyService;
use App\Services\Accounting\ReportService;
use App\Support\Money;

/** Phase 3: banks & cheques, reconciliation, inventory, assets, cost centers, currencies, cash flow. */
class Phase3Test extends AccountingTestCase
{
    private function branchId(): int { return $this->company->branches()->orderBy('id')->first()->id; }
    private function wh(): Warehouse { return Warehouse::where('company_id', $this->company->id)->orderBy('id')->first(); }
    private function inv(): InventoryService { return app(InventoryService::class); }
    private function invoices(): InvoiceService { return app(InvoiceService::class); }

    private function item(array $a = []): Item
    {
        return Item::create($a + ['company_id' => $this->company->id, 'code' => 'P'.random_int(1000, 9999), 'name_ar' => 'لحم غنم', 'unit' => 'كغ',
            'inventory_account_id' => $this->acc('1146'), 'cogs_account_id' => $this->acc('5111'), 'sales_account_id' => $this->acc('4117'), 'tax_code_id' => $this->tax('SR')]);
    }

    private function party(string $type): Party
    {
        return app(PartyService::class)->create($this->company, ['type' => $type, 'name_ar' => $type, 'payment_terms_days' => 30], $this->accountant);
    }

    private function doc(string $type, array $lines, array $a = []): StockDocument
    {
        $d = $this->inv()->saveDocument(new StockDocument($a + ['company_id' => $this->company->id, 'branch_id' => $this->branchId(), 'type' => $type, 'date' => '2026-11-03', 'warehouse_id' => $this->wh()->id]), $lines, $this->entry);

        return $this->inv()->postDocument($d, $this->accountant);
    }

    private function bill(Party $s, array $lines, array $a = []): Invoice
    {
        return $this->invoices()->post($this->invoices()->saveDraft(new Invoice($a + ['company_id' => $this->company->id, 'branch_id' => $this->branchId(), 'kind' => 'purchase', 'payment' => 'credit', 'party_id' => $s->id, 'date' => '2026-11-04', 'supplier_invoice_number' => 'B'.random_int(1, 99999)]), $lines, $this->entry), $this->accountant);
    }

    private function sale(Party $c, array $lines, array $a = []): Invoice
    {
        return $this->invoices()->post($this->invoices()->saveDraft(new Invoice($a + ['company_id' => $this->company->id, 'branch_id' => $this->branchId(), 'kind' => 'sale', 'payment' => 'credit', 'party_id' => $c->id, 'date' => '2026-11-06']), $lines, $this->entry), $this->accountant);
    }

    public function test_weighted_average_cost_and_sale_posts_cogs(): void
    {
        $item = $this->item();
        $this->doc('opening', [['item_id' => $item->id, 'quantity' => '100', 'unit_cost' => '20']], ['counter_account_id' => $this->acc('3111')]);
        $this->bill($this->party('supplier'), [['item_id' => $item->id, 'quantity' => '50', 'unit_price' => '26', 'tax_code_id' => $this->tax('SR')]]);
        $item->refresh();
        $this->assertSame('150.000', $item->qty_on_hand);
        $this->assertSame('22.000000', $item->avg_cost); // (2000 + 1300) / 150
        $this->assertSame('3300.00', $item->stock_value);

        $sale = $this->sale($this->party('customer'), [['item_id' => $item->id, 'quantity' => '30', 'unit_price' => '40']]);
        $lines = $sale->journalEntry->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('660.00', $lines['5111']->debit, 'COGS 30 × 22');
        $this->assertSame('660.00', $lines['1146']->credit);
        $this->assertSame('1200.00', $lines['4117']->credit);
        $this->assertSame('120.000', $item->fresh()->qty_on_hand);

        // the inventory account equals the stock ledger
        $tb = app(ReportService::class)->sums($this->company, null, '2026-11-30');
        $gl = ($tb[$this->acc('1146')]['d'] ?? 0) - ($tb[$this->acc('1146')]['c'] ?? 0);
        $this->assertSame(Money::toCents($item->fresh()->stock_value), $gl);

        // cancelling the sale puts the stock back at the same cost
        $this->invoices()->cancel($sale, $this->chief, 'test');
        $this->assertSame('150.000', $item->fresh()->qty_on_hand);
        $this->assertSame('3300.00', $item->fresh()->stock_value);
    }

    public function test_negative_stock_is_blocked_unless_allowed(): void
    {
        $item = $this->item();
        try {
            $this->sale($this->party('customer'), [['item_id' => $item->id, 'quantity' => '1', 'unit_price' => '10']]);
            $this->fail('negative stock allowed');
        } catch (AccountingException) {
        }
        $this->company->update(['allow_negative_stock' => true]);
        $this->assertSame('posted', $this->sale($this->party('customer'), [['item_id' => $item->id, 'quantity' => '1', 'unit_price' => '10']])->status);
    }

    public function test_item_movement_by_customer_filters_and_defaults_to_all(): void
    {
        $a = $this->item();
        $b = $this->item(['name_ar' => 'لحم بقر']);
        $this->doc('opening', [['item_id' => $a->id, 'quantity' => '100', 'unit_cost' => '20'], ['item_id' => $b->id, 'quantity' => '100', 'unit_cost' => '30']], ['counter_account_id' => $this->acc('3111')]);
        $c1 = $this->party('customer');
        $c2 = $this->party('customer');
        $this->sale($c1, [['item_id' => $a->id, 'quantity' => '10', 'unit_price' => '40'], ['item_id' => $b->id, 'quantity' => '5', 'unit_price' => '60']]);
        $this->sale($c2, [['item_id' => $a->id, 'quantity' => '3', 'unit_price' => '50']]);

        \Filament\Facades\Filament::setTenant($this->company);
        $page = new \App\Filament\Admin\Pages\ItemSalesByCustomer;
        $page->from = '2026-11-01';
        $page->to = '2026-11-30';
        $this->assertCount(3, $page->tables()[1]['rows']); // nothing chosen: all customers, all items

        $page->customer = (string) $c1->id;
        $this->assertCount(2, $page->tables()[1]['rows']);
        $page->item = (string) $a->id;
        $rows = $page->tables()[1]['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame(40000, $rows[0]['cells'][8]);

        $page->customer = null; // item A for all customers
        $sum = $page->tables()[0]['rows'];
        $this->assertCount(3, $sum); // two customers + total
        $this->assertSame(['q' => 13000], end($sum)['cells'][3]);
        $this->assertSame(55000, end($sum)['cells'][6]);
    }

    public function test_dashboard_party_balances_tabs(): void
    {
        $item = $this->item();
        $this->doc('opening', [['item_id' => $item->id, 'quantity' => '100', 'unit_cost' => '20']], ['counter_account_id' => $this->acc('3111')]);
        $c1 = $this->party('customer');
        $c2 = $this->party('customer');
        $this->party('customer'); // no movement: not listed
        $s = $this->party('supplier');
        $this->sale($c1, [['item_id' => $item->id, 'quantity' => '10', 'unit_price' => '100']]); // 1,000 + VAT 50
        $this->sale($c2, [['item_id' => $item->id, 'quantity' => '40', 'unit_price' => '100']]);
        $this->bill($s, [['item_id' => $item->id, 'quantity' => '10', 'unit_price' => '20']]);

        \Filament\Facades\Filament::setTenant($this->company);
        $w = new \App\Filament\Admin\Widgets\PartyBalances;
        $cust = $w->balances('customer');
        $this->assertSame([420000, 105000], array_column($cust['rows'], 'balance'));
        $this->assertSame(525000, $cust['total']);
        $this->assertSame(21000, $w->balances('supplier')['total']); // shown positive: we owe it
    }

    public function test_dashboard_due_transactions(): void
    {
        $this->postedOpening();
        $c = $this->party('customer');
        $sale = $this->sale($c, [['account_id' => $this->acc('4111'), 'description' => 'x', 'quantity' => '1', 'unit_price' => '1000']], ['due_date' => now()->subDays(5)->toDateString()]);
        $v = $this->invoices()->registerPayment($sale, ['date' => now()->toDateString(), 'cash_account_id' => $this->acc('1121'), 'amount' => '300', 'payment_method' => 'cheque', 'cheque_number' => '9'], $this->entry, $this->vouchers());
        $v->forceFill(['cheque_due_date' => now()->subDay()->toDateString()])->saveQuietly();
        $this->vouchers()->post($v->fresh(), $this->accountant);
        $later = $this->vouchers()->post($this->voucher(['type' => 'payment', 'cash_account_id' => $this->acc('1121'), 'counter_account_id' => $this->acc('6220'), 'amount' => '200',
            'payment_method' => 'cheque', 'cheque_number' => '10', 'cheque_due_date' => now()->addMonth()->toDateString()]), $this->accountant);
        $this->assertSame('issued', $later->cheque_status);

        $this->actingAs($this->chief);
        \Filament\Facades\Filament::setTenant($this->company);
        $stats = (fn () => $this->getStats())->call(new \App\Filament\Admin\Widgets\DueTransactions);
        $this->assertSame(['750.00', '300.00', '0.00'], array_map(fn ($s) => (string) $s->getValue(), $stats), 'open 1,050 − 300; one received cheque due; the issued one is next month');
        $this->get(\App\Filament\Admin\Resources\ChequeResource::getUrl('index', ['tenant' => $this->company, 'activeTab' => 'due_received']))->assertOk()->assertSee('9');
    }

    public function test_send_invoice_by_email_and_whatsapp_with_signed_link(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $item = $this->item();
        $this->doc('opening', [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '20']], ['counter_account_id' => $this->acc('3111')]);
        $c = $this->party('customer');
        $inv = $this->sale($c, [['item_id' => $item->id, 'quantity' => '1', 'unit_price' => '100']]);
        $svc = app(\App\Services\Accounting\InvoiceSharing::class);

        $this->actingAs($this->accountant);
        $svc->email($inv, 'buyer@example.com', null, $svc->defaultMessage($inv));
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\InvoiceMail::class, fn ($m) => $m->hasTo('buyer@example.com') && str_contains($m->link, '/i/'.$inv->id));
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'emailed')->where('auditable_id', $inv->id)->count());

        $url = $svc->whatsappUrl($inv, '050 123 4567', 'مرحبا');
        $this->assertStringStartsWith('https://wa.me/971501234567?text=', $url);
        $this->assertSame('971501234567', $svc::normalizePhone('+971 50 123 4567'));
        $this->assertSame('971501234567', $svc::normalizePhone('00971501234567'));

        auth()->logout();
        $this->get($svc->link($inv))->assertOk()->assertSee($inv->number);   // customer opens the link without logging in
        $this->get('/i/'.$inv->id)->assertForbidden();                         // unsigned link is refused
    }

    public function test_last_sale_price_is_remembered_per_customer(): void
    {
        $item = $this->item(['sale_price' => '30']);
        $this->doc('opening', [['item_id' => $item->id, 'quantity' => '100', 'unit_cost' => '20']], ['counter_account_id' => $this->acc('3111')]);
        $c1 = $this->party('customer');
        $c2 = $this->party('customer');
        $this->sale($c1, [['item_id' => $item->id, 'quantity' => '5', 'unit_price' => '41']], ['date' => '2026-11-03']);
        $this->sale($c1, [['item_id' => $item->id, 'quantity' => '5', 'unit_price' => '43.5']], ['date' => '2026-11-05']);
        $this->sale($c2, [['item_id' => $item->id, 'quantity' => '5', 'unit_price' => '38']]);

        \Filament\Facades\Filament::setTenant($this->company);
        $r = \App\Filament\Admin\Resources\SalesInvoiceResource::class;
        $this->assertSame(43.5, $r::lastSalePrice($c1->id, $item->id)['price']);
        $this->assertSame('2026-11-05', $r::lastSalePrice($c1->id, $item->id)['date']);
        $this->assertSame(38.0, $r::lastSalePrice($c2->id, $item->id)['price']);
        $this->assertNull($r::lastSalePrice($this->party('customer')->id, $item->id)); // new customer: item list price is used
        $this->assertNull($r::lastSalePrice($c1->id, $item->id, null, 'USD'));
    }

    public function test_periodic_inventory_posts_purchases_and_closing_adjustment(): void
    {
        $this->company->update(['inventory_system' => 'periodic']);
        $this->company->refresh();
        $item = $this->item();
        $this->doc('opening', [['item_id' => $item->id, 'quantity' => '100', 'unit_cost' => '20']], ['counter_account_id' => $this->acc('3111')]); // inventory 2,000
        $bill = $this->bill($this->party('supplier'), [['item_id' => $item->id, 'quantity' => '50', 'unit_price' => '26', 'tax_code_id' => $this->tax('SR')]]);
        $bl = $bill->journalEntry->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('1300.00', $bl['5111']->debit, 'purchase goes to purchases, not inventory');
        $this->assertArrayNotHasKey('1146', $bl->all());

        $sale = $this->sale($this->party('customer'), [['item_id' => $item->id, 'quantity' => '30', 'unit_price' => '40']]);
        $codes = $sale->journalEntry->lines->map(fn ($l) => $l->account->code)->all();
        $this->assertNotContains('1146', $codes, 'no cost-of-sales entry per sale');
        $this->assertSame('120.000', $item->fresh()->qty_on_hand); // quantities still tracked
        $this->assertSame('22.000000', $item->fresh()->avg_cost);

        $r = app(ReportService::class);
        $d = $this->inv()->postClosing($this->company, '2026-11-30', $this->branchId(), $this->accountant);
        $this->assertSame('posted', $d->status);
        $tb = $r->sums($this->company, null, '2026-11-30');
        $this->assertSame(264000, ($tb[$this->acc('1146')]['d'] ?? 0) - ($tb[$this->acc('1146')]['c'] ?? 0), 'closing stock 120 × 22');
        // cost of sales = opening 2,000 + purchases 1,300 − closing 2,640 = 660, same as the perpetual method
        $this->assertSame(66000, ($tb[$this->acc('5111')]['d'] ?? 0) - ($tb[$this->acc('5111')]['c'] ?? 0));
        $this->assertTrue($r->balanceSheet($this->company, '2026-11-30')['balanced']);

        // a second run with nothing new changes nothing
        $this->assertNull($this->inv()->postClosing($this->company, '2026-11-30', $this->branchId(), $this->accountant)->journal_entry_id);
    }

    public function test_units_conversion_transfer_count_and_landed_cost(): void
    {
        $item = $this->item(['unit' => 'كغ']);
        $item->units()->create(['name' => 'كرتون', 'factor' => '12.5']);
        $wh2 = Warehouse::create(['company_id' => $this->company->id, 'code' => 'W2', 'name_ar' => 'ثلاجة 2']);
        $bill = $this->bill($this->party('supplier'), [['item_id' => $item->id, 'quantity' => '8', 'unit' => 'كرتون', 'unit_price' => '250']]); // 100 kg for 2000
        $this->assertSame('100.000', $item->fresh()->qty_on_hand);

        $this->doc('transfer', [['item_id' => $item->id, 'quantity' => '40']], ['to_warehouse_id' => $wh2->id]);
        $this->assertSame('40.000', $item->fresh()->qtyIn($wh2->id) === '40.000' ? '40.000' : $item->fresh()->qtyIn($wh2->id));

        // count finds 58 kg instead of 60 in the main store: 2 kg shortage at 20 = 40 to inventory variance
        $adj = $this->doc('count', [['item_id' => $item->id, 'quantity' => '58']]);
        $lines = $adj->journalEntry->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('40.00', $lines['5130']->debit);
        $this->assertSame('98.000', $item->fresh()->qty_on_hand);

        // freight & customs of 196 raise the average cost of the 98 kg left: (1960 + 196) / 98 = 22
        $this->doc('landed_cost', [['invoice_id' => $bill->id]], ['amount' => '196', 'allocation' => 'value', 'counter_account_id' => $this->acc('2121')]);
        $this->assertSame('22.000000', $item->fresh()->avg_cost);
    }

    public function test_transfer_between_cash_and_bank(): void
    {
        $this->postedOpening();
        $b = app(BankingService::class);
        $t = $b->saveTransfer(new Transfer(['company_id' => $this->company->id, 'branch_id' => $this->branchId(), 'date' => '2026-11-05', 'from_account_id' => $this->acc('1114'), 'to_account_id' => $this->acc('1121'), 'amount' => '3000']), $this->entry);
        $t = $b->postTransfer($t, $this->accountant);
        $this->assertSame('TRF-2026-00001', $t->number);
        $lines = $t->journalEntry->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('3000.00', $lines['1121']->debit);
        $this->assertSame('3000.00', $lines['1114']->credit);

        // more than the cash box holds is refused (no negative cash)
        $t2 = $b->saveTransfer(new Transfer(['company_id' => $this->company->id, 'branch_id' => $this->branchId(), 'date' => '2026-11-05', 'from_account_id' => $this->acc('1114'), 'to_account_id' => $this->acc('1121'), 'amount' => '999999']), $this->entry);
        $this->expectException(AccountingException::class);
        $b->postTransfer($t2, $this->accountant);
    }

    public function test_cheque_received_deposited_then_returned_reopens_invoice(): void
    {
        $this->postedOpening();
        $c = $this->party('customer');
        $sale = $this->sale($c, [['account_id' => $this->acc('4111'), 'description' => 'x', 'quantity' => 1, 'unit_price' => '1000']]);
        $v = $this->invoices()->registerPayment($sale, ['date' => '2026-11-07', 'cash_account_id' => $this->acc('1121'), 'amount' => '1050', 'payment_method' => 'cheque', 'cheque_number' => '000123'], $this->entry, $this->vouchers());
        $v = $this->vouchers()->post($v, $this->accountant);
        $this->assertSame('in_box', $v->cheque_status);
        $this->assertSame('1050.00', $v->journalEntry->lines->firstWhere('account_id', $this->acc('1119'))->debit, 'cheque waits in cheques under collection');
        $this->assertSame('paid', $sale->fresh()->paymentState());

        $b = app(BankingService::class);
        $v = $b->chequeAction($v, 'deposit', $this->accountant, '2026-11-08', $this->acc('1121'));
        $this->assertSame('deposited', $v->cheque_status);
        $v = $b->chequeAction($v, 'return', $this->accountant, '2026-11-10', null, 'insufficient funds');
        $this->assertSame('returned', $v->cheque_status);
        $this->assertSame(105000, $c->balance());
        $this->assertSame('not_paid', $sale->fresh()->paymentState());
        $sums = app(ReportService::class)->sums($this->company, null, '2026-11-30');
        $this->assertSame(0, ($sums[$this->acc('1119')]['d'] ?? 0) - ($sums[$this->acc('1119')]['c'] ?? 0));
    }

    public function test_bank_reconciliation_import_and_auto_match(): void
    {
        $this->postedOpening(); // bank 50,000 on 2026-11-01
        $v = $this->vouchers()->post($this->voucher(['type' => 'payment', 'cash_account_id' => $this->acc('1121'), 'counter_account_id' => $this->acc('6210'), 'amount' => '1000', 'tax_code_id' => $this->tax('OS'), 'payment_method' => 'bank_transfer', 'date' => '2026-11-12']), $this->accountant);
        $csv = tempnam(sys_get_temp_dir(), 'st').'.csv';
        file_put_contents($csv, "Date,Description,Reference,Debit,Credit\n01/11/2026,Opening deposit,,,50000\n13/11/2026,Rent transfer,,1000,\n30/11/2026,Bank charges,,25,\n");
        $b = app(BankingService::class);
        $rows = $b->readStatementFile($csv);
        $this->assertCount(3, $rows);
        $this->assertSame('-1000.00', $rows[1]['amount']);
        $st = $b->importStatement(\App\Models\Account::find($this->acc('1121')), $rows, ['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'opening_balance' => 0, 'closing_balance' => '48975'], $this->accountant, 'nov.csv');
        $sum = $b->summary($st);
        $this->assertSame(2, $sum['matched']);
        $this->assertSame(0, $sum['statement_check']);
        $this->assertSame(-2500, $sum['unmatched_cents'], 'bank charges not yet booked');
        $this->assertSame(0, $sum['difference']);
    }

    public function test_depreciation_run_and_disposal_with_gain(): void
    {
        $cat = AssetCategory::where('company_id', $this->company->id)->where('asset_account_id', $this->acc('1215'))->firstOrFail();
        $svc = app(AssetService::class);
        $a = $svc->create($this->company, ['branch_id' => $this->branchId(), 'asset_category_id' => $cat->id, 'name_ar' => 'شاحنة مبردة', 'purchase_date' => '2026-01-10', 'cost' => '120000', 'salvage_value' => '12000', 'useful_life_months' => 60, 'opening_accumulated' => '16200'], $this->chief);
        $run = $svc->run($this->company, '2026-11-30', $this->chief);
        $this->assertSame('1800.00', $run->total); // (120000 - 12000) / 60
        $this->assertSame('DEP-2026-00001', $run->journalEntry->number);
        try {
            $svc->run($this->company, '2026-11-15', $this->chief);
            $this->fail('ran twice');
        } catch (AccountingException) {
        }
        $this->postedOpening();
        // book value 120000 - 18000 = 102000, sold for 105000 → gain 3000
        $svc->dispose($a, '2026-11-30', '105000', $this->acc('1121'), $this->chief, 'sold');
        $lines = Asset::find($a->id)->disposalEntry->lines->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('18000.00', $lines['1224']->debit);
        $this->assertSame('120000.00', $lines['1215']->credit);
        $this->assertSame('3000.00', $lines['4240']->credit);
        $reg = app(ReportService::class)->assetRegister($this->company, '2026-11-30');
        $this->assertSame(0, $reg['totals']['nbv']);
    }

    public function test_cost_center_profit_and_report_filter(): void
    {
        $cc = \App\Models\CostCenter::create(['company_id' => $this->company->id, 'code' => 'P1', 'name_ar' => 'مشروع']);
        $this->sale($this->party('customer'), [['account_id' => $this->acc('4111'), 'description' => 'x', 'quantity' => 1, 'unit_price' => '500', 'cost_center_id' => $cc->id]]);
        $e = $this->draft([['account_id' => $this->acc('6210'), 'debit' => '200', 'credit' => 0, 'cost_center_id' => $cc->id], ['account_id' => $this->acc('2121'), 'debit' => 0, 'credit' => '200']]);
        $this->posting()->post($e, $this->accountant);
        $p = app(ReportService::class)->costCenterProfit($this->company, '2026-11-01', '2026-11-30');
        $row = collect($p['rows'])->first(fn ($r) => $r['center']?->id === $cc->id);
        $this->assertSame(50000, $row['revenue']);
        $this->assertSame(20000, $row['expense']);
        $this->assertSame(30000, $row['net']);
        $is = app(ReportService::class)->forCostCenter($cc->id)->incomeStatement($this->company, '2026-11-01', '2026-11-30');
        $this->assertSame(30000, $is['net']);
    }

    public function test_foreign_currency_bill_converts_to_aed_and_fx_difference_written_off(): void
    {
        ExchangeRate::create(['company_id' => $this->company->id, 'currency' => 'USD', 'date' => '2026-11-01', 'rate' => '3.6725']);
        $s = $this->party('supplier');
        $bill = $this->bill($s, [['account_id' => $this->acc('6210'), 'description' => 'service', 'quantity' => 1, 'unit_price' => '1000', 'tax_code_id' => $this->tax('OS')]], ['currency' => 'USD']);
        $this->assertSame('3.672500', $bill->fx_rate);
        $this->assertSame('3672.50', $bill->base_total);
        $party = $bill->journalEntry->lines->firstWhere('party_id', $s->id);
        $this->assertSame('3672.50', $party->credit);
        $this->assertSame('USD', $party->currency);
        $this->assertSame('1000.00', $party->amount_fc);
        // paid 3,670.00 AED at a better rate: the 2.50 left is written off to exchange gains
        $this->postedOpening();
        $v = $this->invoices()->registerPayment($bill, ['date' => '2026-11-20', 'cash_account_id' => $this->acc('1121'), 'amount' => '3670', 'payment_method' => 'bank_transfer'], $this->entry, $this->vouchers());
        $this->vouchers()->post($v, $this->accountant);
        $this->invoices()->writeOffDifference($bill->fresh(), $this->chief, 'fx');
        $this->assertSame('paid', $bill->fresh()->paymentState());
        $this->assertSame(0, $s->balance());
    }

    public function test_cash_flow_reconciles_to_cash_movement(): void
    {
        $this->postedOpening();
        $item = $this->item();
        $this->bill($this->party('supplier'), [['item_id' => $item->id, 'quantity' => '10', 'unit_price' => '100']], ['payment' => 'cash', 'cash_account_id' => $this->acc('1114')]);
        $this->sale($this->party('customer'), [['item_id' => $item->id, 'quantity' => '4', 'unit_price' => '300']], ['payment' => 'cash', 'cash_account_id' => $this->acc('1114')]);
        $cf = app(ReportService::class)->cashFlow($this->company, '2026-11-01', '2026-11-30');
        $this->assertSame(0, $cf['check']);
        $this->assertSame(80000, $cf['net_income']); // 1200 - 400
        $mv = app(ReportService::class)->cashMovement($this->company, '2026-11-01', '2026-11-30');
        $this->assertSame($cf['cash_close'], $mv['totals']['closing']);
    }

    public function test_excel_import_of_master_data_and_opening_balances(): void
    {
        $imp = app(\App\Services\Accounting\DataImporter::class);
        $file = function (string $type, array $rows) use ($imp) {
            $book = $imp->template($type);
            $sheet = $book->getActiveSheet();
            $keys = array_keys(\App\Services\Accounting\DataImporter::columns($type));
            $sheet->removeRow(2); // the grey example
            foreach ($rows as $i => $r) {
                foreach ($r as $k => $v) {
                    $sheet->setCellValue([array_search($k, $keys, true) + 1, $i + 2], $v);
                }
            }
            $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

            return $imp->readRows($path, $type);
        };
        $r = $imp->import($this->company, 'customers', $file('customers', [
            ['name_ar' => 'مطعم الندى', 'trn' => '100111222300003', 'tax' => 'SR', 'payment_terms_days' => 15],
            ['name_ar' => 'شركة تصدير', 'tax' => 'ZR'],
            ['name_ar' => 'خطأ', 'trn' => '123'],
        ]), $this->chief);
        $this->assertSame(2, $r['created']);
        $this->assertCount(1, $r['errors']);
        $this->assertSame('1131-0002', Party::where('company_id', $this->company->id)->where('name_ar', 'شركة تصدير')->value('code'));
        $this->assertSame('ZR', Party::where('name_ar', 'شركة تصدير')->first()->taxCode->code);
        // importing the same TRN again updates instead of duplicating
        $r = $imp->import($this->company, 'customers', $file('customers', [['name_ar' => 'مطعم الندى الجديد', 'trn' => '100111222300003']]), $this->chief);
        $this->assertSame(1, $r['updated']);

        $r = $imp->import($this->company, 'items', $file('items', [
            ['code' => 'P10', 'name_ar' => 'لحم غنم', 'unit' => 'كغ', 'sale_price' => '45', 'sales_account' => '4117', 'unit2' => 'كرتونة', 'unit2_factor' => '10'],
            ['code' => 'S10', 'name_ar' => 'توصيل', 'type' => 'service', 'sales_account' => '4111'],
        ]), $this->chief);
        $this->assertSame(2, $r['created'], json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('10.000000', (string) Item::where('code', 'P10')->first()->units->first()->factor);

        $r = $imp->import($this->company->fresh(), 'opening_stock', $file('opening_stock', [['item' => 'P10', 'quantity' => '200', 'unit_cost' => '30'], ['item' => 'S10', 'quantity' => '1', 'unit_cost' => '1']]),
            $this->chief, ['counter_account_id' => $this->acc('3111'), 'date' => '2026-11-01']);
        $this->assertSame(1, $r['created']);
        $this->assertCount(1, $r['errors']);
        $this->assertSame('6000.00', (string) Item::where('code', 'P10')->first()->stock_value);

        $r = $imp->import($this->company->fresh(), 'assets', $file('assets', [['name_ar' => 'شاحنة مبردة', 'category_account' => '1215', 'purchase_date' => '2025-06-10', 'cost' => '120000', 'opening_accumulated' => '20000']]), $this->chief);
        $this->assertSame(1, $r['created'], json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(60, Asset::where('name_ar', 'شاحنة مبردة')->first()->useful_life_months);

        $r = $imp->import($this->company->fresh(), 'open_invoices', $file('open_invoices', [['party_type' => 'customer', 'party' => '1131-0001', 'number' => 'OLD-77', 'date' => '2026-10-20', 'amount' => '5250']]),
            $this->chief, ['counter_account_id' => $this->acc('3111')]);
        $this->assertSame(1, $r['created'], json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
        $old = Invoice::where('description', 'like', '%OLD-77%')->first();
        $this->assertSame('posted', $old->status);
        $this->assertSame(525000, $old->openCents());
        $this->assertSame('0.00', (string) $old->tax_total);
    }
}
