<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Accounting\CompanySetup;
use Illuminate\Database\Seeder;

/**
 * Three separate companies (confirmed by the client): the slaughterhouse and two delis,
 * each with its own TRN, books and VAT return. Names, TRNs and emirates are placeholders
 * to edit under Settings.
 */
class DatabaseSeeder extends Seeder
{
    public const COMPANIES = [
        ['name_ar' => 'شركة الذبائح', 'name_en' => 'Meat Company', 'activity' => 'slaughterhouse'],
        ['name_ar' => 'مطعم دلي ١', 'name_en' => 'Deli One', 'activity' => 'restaurant'],
        ['name_ar' => 'مطعم دلي ٢', 'name_en' => 'Deli Two', 'activity' => 'restaurant'],
    ];

    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $setup = app(CompanySetup::class);
        $chart = database_path('data/chart-of-accounts.xlsx');
        $year = env('FISCAL_YEAR_START', now()->startOfYear()->toDateString());

        $companies = collect(self::COMPANIES)->map(function ($c) use ($setup, $chart, $year) {
            $sisters = collect(self::COMPANIES)->where('name_ar', '!=', $c['name_ar'])->pluck('name_ar')->all();

            return $setup->create(['name_ar' => $c['name_ar'], 'name_en' => $c['name_en'], 'emirate' => 'AUH'], $c['activity'], $chart, $year, $sisters);
        });

        $users = [
            ['مدير النظام', 'admin@example.com', 'system_admin'],
            ['رئيس الحسابات', 'chief@example.com', 'chief_accountant'],
            ['المحاسب', 'accountant@example.com', 'accountant'],
            ['مدخل البيانات', 'entry@example.com', 'data_entry'],
            ['الإدارة', 'management@example.com', 'management'],
        ];
        foreach ($users as [$name, $email, $role]) {
            $u = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => env('SEED_PASSWORD', 'password'), 'locale' => 'ar']);
            $u->syncRoles([$role]);
            $u->companies()->syncWithoutDetaching($companies->pluck('id'));
        }

        if (env('SEED_DEMO', false)) {
            $this->call(DemoSeeder::class);
        }
    }
}
