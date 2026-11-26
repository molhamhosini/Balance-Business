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
        }
    }
}
