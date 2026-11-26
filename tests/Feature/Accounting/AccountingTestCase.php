<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\CompanySetup;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\VoucherService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AccountingTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $entry;
    protected User $accountant;
    protected User $chief;
    protected User $management;
    protected string $month = '2026-11';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->company = app(CompanySetup::class)->create(['name_ar' => 'شركة الذبائح', 'name_en' => 'Meat Co', 'emirate' => 'AUH'], 'slaughterhouse', database_path('data/chart-of-accounts.xlsx'), '2026-01-01', ['مطعم دلي ١', 'مطعم دلي ٢']);
        foreach (['entry' => 'data_entry', 'accountant' => 'accountant', 'chief' => 'chief_accountant', 'management' => 'management'] as $prop => $role) {
            $u = User::factory()->create();
            $u->assignRole($role);
            $u->companies()->attach($this->company);
            $this->{$prop} = $u;
        }
        $this->actingAs($this->accountant);
    }

    protected function acc(string $code): int
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->value('id');
    }

    protected function tax(string $code): int
    {
        return TaxCode::where('company_id', $this->company->id)->where('code', $code)->value('id');
    }

    protected function posting(): PostingService { return app(PostingService::class); }
    protected function vouchers(): VoucherService { return app(VoucherService::class); }

    protected function draft(array $lines, string $date = '2026-11-10', ?User $by = null)
    {
        return $this->posting()->createDraft(['company_id' => $this->company->id, 'branch_id' => $this->company->branches()->orderBy('id')->first()->id, 'date' => $date, 'description' => 'test'], $lines, $by ?? $this->entry);
    }

    protected function postedOpening(string $cash = '10000')
    {
        $e = $this->draft([
            ['account_id' => $this->acc('1114'), 'debit' => $cash, 'credit' => 0],
            ['account_id' => $this->acc('1121'), 'debit' => '50000', 'credit' => 0],
            ['account_id' => $this->acc('3111'), 'debit' => 0, 'credit' => (string) (50000 + (float) $cash)],
        ], '2026-11-01');

        return $this->posting()->post($e, $this->accountant);
    }

    protected function voucher(array $attrs): Voucher
    {
        return $this->vouchers()->saveDraft(new Voucher($attrs + ['company_id' => $this->company->id, 'branch_id' => $this->company->branches()->orderBy('id')->first()->id, 'date' => '2026-11-12', 'payment_method' => 'cash']), $this->entry);
    }
}
