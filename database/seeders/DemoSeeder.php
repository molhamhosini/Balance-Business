<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Company;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Voucher;
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
        }
    }
}
