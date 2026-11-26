<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Company;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\PartyService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\VoucherService;
use Illuminate\Database\Seeder;

/** Sample transactions for trying the system (php artisan db:seed --class=DemoSeeder). Not for production. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $posting = app(PostingService::class);
        $vouchers = app(VoucherService::class);
        $entry = User::where('email', 'entry@example.com')->first();
        $acct = User::where('email', 'accountant@example.com')->first();
        $chief = User::where('email', 'chief@example.com')->first();
        $month = now()->format('Y-m');

        foreach (Company::all() as $company) {
            $branch = $company->branches()->orderBy("id")->first();
            $a = fn ($code) => Account::where('company_id', $company->id)->where('code', $code)->value('id');
            $tax = fn ($code) => TaxCode::where('company_id', $company->id)->where('code', $code)->value('id');
            $isMeat = $branch->activity === 'slaughterhouse';

            $opening = $posting->createDraft(['company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'opening', 'date' => "$month-01", 'description' => 'القيد الافتتاحي'], [
                ['account_id' => $a('1114'), 'debit' => '25000', 'credit' => 0],
                ['account_id' => $a('1121'), 'debit' => '300000', 'credit' => 0],
                ['account_id' => $a($isMeat ? '1145' : '1147'), 'debit' => '60000', 'credit' => 0],
                ['account_id' => $a('1216'), 'debit' => '115000', 'credit' => 0],
                ['account_id' => $a('3111'), 'debit' => 0, 'credit' => '450000'],
                ['account_id' => $a('2111'), 'debit' => 0, 'credit' => '50000'],
            ], $entry);
            $posting->post($opening, $acct);

            $sale = $vouchers->saveDraft(new Voucher(['company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'receipt', 'date' => "$month-05",
                'cash_account_id' => $a('1114'), 'counter_account_id' => $a($isMeat ? '4117' : '4131'), 'tax_code_id' => $tax('SR'),
                'amount' => $isMeat ? '18400' : '26500', 'party_name' => 'مبيعات نقدية', 'description' => 'مبيعات الأسبوع الأول']), $entry);
            $vouchers->post($sale, $acct);

            $rent = $vouchers->saveDraft(new Voucher(['company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'payment', 'date' => "$month-06",
                'cash_account_id' => $a('1121'), 'counter_account_id' => $a('6210'), 'tax_code_id' => $tax('SR'),
                'amount' => '12000', 'party_name' => 'المالك', 'description' => 'إيجار الشهر', 'payment_method' => 'bank_transfer']), $entry);
            $vouchers->post($rent, $chief); // above approval limit -> chief accountant

            $power = $vouchers->saveDraft(new Voucher(['company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'payment', 'date' => "$month-08",
                'cash_account_id' => $a('1114'), 'counter_account_id' => $a('6220'), 'tax_code_id' => $tax('SR'),
                'amount' => '3600', 'party_name' => 'هيئة كهرباء ومياه دبي', 'description' => 'فاتورة الكهرباء']), $entry);
            $vouchers->post($power, $acct);

            $wrong = $posting->createDraft(['company_id' => $company->id, 'branch_id' => $branch->id, 'date' => "$month-09", 'description' => 'قيد خاطئ للتجربة'], [
                ['account_id' => $a('6470'), 'debit' => '500', 'credit' => 0],
                ['account_id' => $a('1114'), 'debit' => 0, 'credit' => '500'],
            ], $entry);
            $posting->post($wrong, $acct);
            $posting->reverse($wrong->fresh(), $chief, 'خطأ في الحساب');

            $vouchers->saveDraft(new Voucher(['company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'payment', 'date' => "$month-10",
                'cash_account_id' => $a('1121'), 'counter_account_id' => $a('2122'), 'tax_code_id' => $tax('OS'),
                'amount' => '21000', 'party_name' => 'الموظفون', 'description' => 'رواتب الشهر (بانتظار الاعتماد)', 'payment_method' => 'bank_transfer']), $entry);

            $posting->createDraft(['company_id' => $company->id, 'branch_id' => $branch->id, 'date' => "$month-11", 'description' => 'إثبات رواتب مستحقة'], [
                ['account_id' => $a('6110'), 'debit' => '21000', 'credit' => 0],
                ['account_id' => $a('2122'), 'debit' => 0, 'credit' => '21000'],
            ], $entry);

            // Phase 2: customers, suppliers, invoices and payments
            $parties = app(PartyService::class);
            $invoices = app(InvoiceService::class);
            $hotel = $parties->create($company, ['type' => 'customer', 'name_ar' => 'فندق الواحة', 'name_en' => 'Oasis Hotel', 'trn' => '100234567800003', 'payment_terms_days' => 30, 'credit_limit' => '50000', 'credit_limit_action' => 'block', 'emirate' => 'AUH', 'address' => 'أبوظبي، الكورنيش'], $acct);
            $walkin = $parties->create($company, ['type' => 'customer', 'name_ar' => 'عميل نقدي', 'name_en' => 'Walk-in customer', 'payment_terms_days' => 0], $acct);
            $farm = $parties->create($company, ['type' => 'supplier', 'name_ar' => $isMeat ? 'مزارع الخليج للمواشي' : 'شركة الذبائح', 'name_en' => $isMeat ? 'Gulf Livestock Farms' : 'Meat Company', 'trn' => '100987654300003', 'payment_terms_days' => 15, 'is_related_party' => ! $isMeat], $acct);
            $rev = $a($isMeat ? '4117' : '4131') ?? $a('4111');
            $mk = fn (string $kind, $party, string $day, array $lines, array $extra = []) => $invoices->saveDraft(new Invoice($extra + ['company_id' => $company->id, 'branch_id' => $branch->id, 'kind' => $kind, 'payment' => 'credit', 'party_id' => $party->id, 'date' => "$month-$day"]), $lines, $entry);

            $inv1 = $invoices->post($mk('sale', $hotel, '03', [
                ['account_id' => $rev, 'description' => $isMeat ? 'ذبائح غنم محلي' : 'وجبات فطور للضيوف', 'quantity' => $isMeat ? '120.5' : '80', 'unit' => $isMeat ? 'كغ' : 'وجبة', 'unit_price' => $isMeat ? '42' : '35', 'tax_code_id' => $tax('SR')],
                ['account_id' => $rev, 'description' => $isMeat ? 'لحم بقري مبرد' : 'ساندويتشات', 'quantity' => '40', 'unit' => $isMeat ? 'كغ' : 'قطعة', 'unit_price' => '38.5', 'tax_code_id' => $tax('SR')],
            ]), $acct);
            $vouchers->post($invoices->registerPayment($inv1, ['date' => "$month-15", 'cash_account_id' => $a('1121'), 'amount' => '3000', 'payment_method' => 'bank_transfer'], $entry, $vouchers), $acct);
            $invoices->post($mk('sale', $walkin, '12', [['account_id' => $rev, 'description' => 'مبيعات نقدية', 'quantity' => '1', 'unit_price' => '950', 'tax_code_id' => $tax('SR')]], ['payment' => 'cash', 'cash_account_id' => $a('1114')]), $acct);
            $old = $invoices->saveDraft(new Invoice(['company_id' => $company->id, 'branch_id' => $branch->id, 'kind' => 'sale', 'payment' => 'credit', 'party_id' => $hotel->id, 'date' => now()->subMonths(3)->format('Y-m-15')]),
                [['account_id' => $rev, 'description' => 'طلبية سابقة', 'quantity' => '1', 'unit_price' => '2400', 'tax_code_id' => $tax('SR')]], $entry);
            $invoices->post($old, $acct);
            $cn = $invoices->makeReturn($inv1, $entry);
            $first = $cn->lines->first();
            $invoices->saveDraft($cn, [['account_id' => $first->account_id, 'description' => 'مرتجع: '.$first->description, 'quantity' => '5', 'unit' => $first->unit, 'unit_price' => $first->unit_price, 'tax_code_id' => $first->tax_code_id]], $entry);
            $invoices->post($cn, $acct);
            $mk('sale', $hotel, '20', [['account_id' => $rev, 'description' => 'طلبية الأسبوع القادم', 'quantity' => '10', 'unit_price' => '45', 'tax_code_id' => $tax('SR')]]);

            $bill = $invoices->post($mk('purchase', $farm, '02', [
                ['account_id' => $a($isMeat ? '1145' : '5111'), 'description' => $isMeat ? 'رؤوس أغنام حية' : 'لحوم مشكلة', 'quantity' => $isMeat ? '60' : '300', 'unit' => $isMeat ? 'رأس' : 'كغ', 'unit_price' => $isMeat ? '650' : '36', 'tax_code_id' => $tax('SR')],
            ], ['supplier_invoice_number' => 'F-'.(1000 + $company->id)]), $acct);
            $vouchers->post($invoices->registerPayment($bill, ['date' => "$month-10", 'cash_account_id' => $a('1121'), 'amount' => $isMeat ? '20000' : '5000', 'payment_method' => 'bank_transfer'], $entry, $vouchers), $chief);

            // Phase 3: items and stock, cheques, transfers, fixed assets, currencies
            $inventory = app(\App\Services\Accounting\InventoryService::class);
            $banking = app(\App\Services\Accounting\BankingService::class);
            \App\Models\ExchangeRate::create(['company_id' => $company->id, 'currency' => 'USD', 'date' => "$month-01", 'rate' => '3.6725']);
            $wh = \App\Models\Warehouse::where('company_id', $company->id)->orderBy('id')->first();
            $mkItem = fn (string $code, string $ar, string $en, string $unit, string $price, string $type = 'stock') => \App\Models\Item::create([
                'company_id' => $company->id, 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'type' => $type, 'unit' => $unit, 'sale_price' => $price,
                'tax_code_id' => $tax('SR'), 'sales_account_id' => $rev, 'inventory_account_id' => $a('1141'), 'cogs_account_id' => $a('5111'),
            ]);
            $lamb = $mkItem('P00001', $isMeat ? 'لحم غنم محلي' : 'لحم مفروم', $isMeat ? 'Local lamb' : 'Minced meat', 'كغ', $isMeat ? '45' : '38');
            $lamb->units()->create(['name' => 'كرتونة', 'factor' => 10, 'sale_price' => $isMeat ? '430' : '360']);
            $beef = $mkItem('P00002', $isMeat ? 'لحم بقري مبرد' : 'جبنة بيضاء', $isMeat ? 'Chilled beef' : 'White cheese', 'كغ', $isMeat ? '39' : '28');
            $mkItem('S00001', 'خدمة تقطيع وتغليف', 'Cutting & packing', 'خدمة', '15', 'service');
            $ost = $inventory->saveDocument(new \App\Models\StockDocument(['company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'opening', 'date' => "$month-01",
                'warehouse_id' => $wh->id, 'counter_account_id' => $a('3111'), 'description' => 'رصيد افتتاحي للمخزون']), [
                ['item_id' => $lamb->id, 'quantity' => '500', 'unit_cost' => $isMeat ? '30' : '24'],
                ['item_id' => $beef->id, 'quantity' => '300', 'unit_cost' => $isMeat ? '27' : '18'],
            ], $entry);
            $inventory->postDocument($ost, $acct);
            $invoices->post($mk('sale', $hotel, '18', [
                ['item_id' => $lamb->id, 'quantity' => '12', 'unit' => 'كرتونة', 'unit_price' => $isMeat ? '430' : '360', 'tax_code_id' => $tax('SR')],
                ['item_id' => $beef->id, 'quantity' => '35', 'unit_price' => $isMeat ? '39' : '28', 'tax_code_id' => $tax('SR')],
            ]), $acct);
            $iss = $inventory->saveDocument(new \App\Models\StockDocument(['company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'issue', 'date' => "$month-19",
                'warehouse_id' => $wh->id, 'counter_account_id' => $a('6470') ?? $a('5111'), 'description' => 'هالك وتالف']), [['item_id' => $beef->id, 'quantity' => '4']], $entry);
            $inventory->postDocument($iss, $acct);

            $chq = $vouchers->saveDraft(new Voucher(['company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'receipt', 'date' => "$month-16",
                'cash_account_id' => $a('1121'), 'party_id' => $hotel->id, 'counter_account_id' => $hotel->account_id, 'tax_code_id' => $tax('OS'), 'amount' => '2500',
                'party_name' => $hotel->name_ar, 'payment_method' => 'cheque', 'cheque_number' => '000'.(4410 + $company->id), 'cheque_bank' => 'بنك أبوظبي الأول',
                'cheque_due_date' => "$month-25", 'description' => 'شيك دفعة على الحساب']), $entry);
            $chq = $vouchers->post($chq, $acct);
            $banking->chequeAction($chq->fresh(), 'deposit', $acct, "$month-25", $a('1121'));
            $vouchers->post($vouchers->saveDraft(new Voucher(['company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'receipt', 'date' => "$month-21",
                'cash_account_id' => $a('1121'), 'party_id' => $hotel->id, 'counter_account_id' => $hotel->account_id, 'tax_code_id' => $tax('OS'), 'amount' => '1800',
                'party_name' => $hotel->name_ar, 'payment_method' => 'cheque', 'cheque_number' => '000'.(5520 + $company->id), 'cheque_bank' => 'بنك الإمارات دبي الوطني',
                'cheque_due_date' => now()->addDays(10)->toDateString(), 'description' => 'شيك مؤجل']), $entry), $acct);

            $trf = $banking->saveTransfer(new \App\Models\Transfer(['company_id' => $company->id, 'branch_id' => $branch->id, 'date' => "$month-14",
                'from_account_id' => $a('1114'), 'to_account_id' => $a('1121'), 'amount' => '8000', 'reference' => 'DEP-'.(77 + $company->id), 'description' => 'إيداع نقدية الصندوق في البنك']), $entry);
            $banking->postTransfer($trf, $acct);

            $cat = \App\Models\AssetCategory::where('company_id', $company->id)->where('asset_account_id', $a('1216'))->first();
            if ($cat) {
                $assets = app(\App\Services\Accounting\AssetService::class);
                $assets->create($company, ['branch_id' => $branch->id, 'asset_category_id' => $cat->id, 'name_ar' => $isMeat ? 'غرفة تبريد رئيسية' : 'ثلاجات عرض', 'name_en' => $isMeat ? 'Main cold room' : 'Display chillers',
                    'purchase_date' => now()->subYear()->format('Y-m-01'), 'cost' => '115000', 'salvage_value' => '10000', 'useful_life_months' => 84,
                    'opening_accumulated' => '15000', 'location' => $branch->name], $chief);
                $assets->run($company, "$month-01", $chief);
            }
        }
    }
}
