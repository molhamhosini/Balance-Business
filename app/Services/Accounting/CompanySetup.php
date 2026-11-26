<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\TaxCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates a ready-to-use company: branch, fiscal year, chart of accounts, system bindings, tax codes. */
class CompanySetup
{
    public const SYSTEM_BINDINGS = [
        'vat_output' => '2131', 'vat_input' => '1161', 'retained_earnings' => '3131', 'current_year_pl' => '3132',
        'fx_gain' => '4220', 'fx_loss' => '6610', 'inventory_variance' => '5130',
        'customers' => '1131', 'suppliers' => '2111', 'banks' => '1120',
    ];

    public const CASH_CODES = ['1111', '1112', '1114'];

    public const BANK_CODES = ['1121', '1122'];

    public const NON_DEDUCTIBLE = ['6640'];

    public function __construct(private ChartImporter $importer, private PeriodService $periods) {}

    public function create(array $data, string $activity, string $chartPath, string $fiscalYearStart, array $sisterCompanies = []): Company
    {
        return DB::transaction(function () use ($data, $activity, $chartPath, $fiscalYearStart, $sisterCompanies) {
            $emirate = $data['emirate'] ?? 'AUH';
            unset($data['emirate']);
            $company = Company::create($data + ['slug' => Str::slug($data['name_en'] ?? Str::random(6))]);
            Branch::create([
                'company_id' => $company->id, 'code' => 'MAIN', 'name_ar' => 'الفرع الرئيسي', 'name_en' => 'Main Branch',
                'emirate' => $emirate, 'activity' => $activity,
            ]);

            $this->periods->createYear($company, $fiscalYearStart);
            $this->importer->import($company, $this->importer->readRows($chartPath), 'excel');
            $this->importer->import($company, ActivityAccounts::rows($activity, $sisterCompanies), 'activity');

            $acc = fn (string $code) => Account::where('company_id', $company->id)->where('code', $code)->first();
            foreach (self::SYSTEM_BINDINGS as $key => $code) {
                $acc($code)?->update(['system_key' => $key]);
            }
            Account::where('company_id', $company->id)->whereIn('code', self::CASH_CODES)->update(['is_cash' => true]);
            Account::where('company_id', $company->id)->whereIn('code', self::BANK_CODES)->update(['is_bank' => true]);
            Account::where('company_id', $company->id)->whereIn('code', self::NON_DEDUCTIBLE)->update(['is_non_deductible' => true]);

            $out = $acc('2131')?->id;
            $in = $acc('1161')?->id;
            foreach ([
                ['SR', 'النسبة الأساسية 5%', 'Standard rated 5%', 'standard', 5],
                ['ZR', 'نسبة الصفر', 'Zero rated', 'zero', 0],
                ['EX', 'معفاة', 'Exempt', 'exempt', 0],
                ['OS', 'خارج النطاق', 'Out of scope', 'out_of_scope', 0],
                ['RC', 'الاحتساب العكسي', 'Reverse charge', 'reverse_charge', 5],
            ] as [$code, $ar, $en, $type, $rate]) {
                TaxCode::create(['company_id' => $company->id, 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'type' => $type, 'rate' => $rate, 'output_account_id' => $out, 'input_account_id' => $in]);
            }

            return $company;
        });
    }
}
