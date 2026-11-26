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
        'cheques_in' => '1119', 'cheques_out' => '2126', 'inventory' => '1141', 'cogs' => '5111',
        'freight_in' => '5120', 'depreciation' => '6510', 'asset_gain' => '4240', 'asset_loss' => '6630',
    ];

    /** Fixed asset categories: [name_ar, name_en, asset, accumulated, life in months]. */
    public const ASSET_CATEGORIES = [
        ['المباني والتحسينات', 'Buildings & improvements', '1212', '1221', 240],
        ['الأثاث والتجهيزات', 'Furniture & fittings', '1213', '1222', 60],
        ['أجهزة الحاسب والبرمجيات', 'Computers & software', '1214', '1223', 36],
        ['المركبات', 'Vehicles', '1215', '1224', 60],
        ['معدات المستودعات', 'Warehouse equipment', '1216', '1225', 84],
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

            $this->setupPhase3($company, $activity);

            return $company;
        });
    }

    /** Accounts, bindings and defaults added in phase 3 (also applied to existing companies by the migration). */
    public function setupPhase3(Company $company, ?string $activity = null): void
    {
        $activity ??= $company->branches()->orderBy('id')->value('activity') ?? 'slaughterhouse';
        $existing = Account::where('company_id', $company->id)->pluck('code')->all();
        $missing = array_values(array_filter(ActivityAccounts::rows($activity), fn ($r) => ! in_array($r['code'], $existing, true) && in_array($r['code'], ['1119', '2126'], true)));
        if ($missing) {
            $this->importer->import($company, $missing, 'activity');
        }
        $acc = fn (string $code) => Account::where('company_id', $company->id)->where('code', $code)->first();
        foreach (self::SYSTEM_BINDINGS as $key => $code) {
            if (! Account::where('company_id', $company->id)->where('system_key', $key)->exists()) {
                $acc($code)?->update(['system_key' => $key]);
            }
        }
        foreach ($company->branches as $b) {
            \App\Models\Warehouse::firstOrCreate(['company_id' => $company->id, 'code' => $b->code], ['branch_id' => $b->id, 'name_ar' => 'مستودع '.$b->name_ar, 'name_en' => $b->name_en ? $b->name_en.' Store' : null]);
            \App\Models\CostCenter::firstOrCreate(['company_id' => $company->id, 'code' => $b->code], ['name_ar' => $b->name_ar, 'name_en' => $b->name_en]);
        }
        $exp = $acc('6510');
        foreach (self::ASSET_CATEGORIES as [$ar, $en, $asset, $accum, $life]) {
            $a = $acc($asset);
            $d = $acc($accum);
            if ($a && $d && $exp) {
                \App\Models\AssetCategory::firstOrCreate(['company_id' => $company->id, 'asset_account_id' => $a->id],
                    ['name_ar' => $ar, 'name_en' => $en, 'accumulated_account_id' => $d->id, 'expense_account_id' => $exp->id, 'useful_life_months' => $life]);
            }
        }
    }

    public function upgradeAll(): void
    {
        foreach (Company::all() as $c) {
            $this->setupPhase3($c);
        }
    }
}
