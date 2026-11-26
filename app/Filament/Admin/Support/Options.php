<?php

namespace App\Filament\Admin\Support;

use App\Models\Account;
use App\Models\Branch;
use App\Models\TaxCode;
use Filament\Facades\Filament;

/** Tenant-scoped option lists for selects. */
class Options
{
    public static function companyId(): int
    {
        return Filament::getTenant()->id;
    }

    public static function branches(): array
    {
        $allowed = auth()->user()->allowedBranchIds(self::companyId());

        return Branch::where('company_id', self::companyId())->where('is_active', true)
            ->when($allowed, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => $b->name])->all();
    }

    public static function postableAccounts(?callable $filter = null): array
    {
        $q = Account::where('company_id', self::companyId())->postable()->orderBy('code');
        if ($filter) {
            $filter($q);
        }

        return $q->get()->mapWithKeys(fn ($a) => [$a->id => $a->label])->all();
    }

    public static function cashAndBank(): array
    {
        return self::postableAccounts(fn ($q) => $q->where(fn ($w) => $w->where('is_cash', true)->orWhere('is_bank', true)));
    }

    public static function taxCodes(): array
    {
        return TaxCode::where('company_id', self::companyId())->where('is_active', true)->orderBy('id')->get()->mapWithKeys(fn ($t) => [$t->id => $t->label])->all();
    }

    public static function allAccounts(): array
    {
        return Account::where('company_id', self::companyId())->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => str_repeat('  ', $a->level - 1).$a->label])->all();
    }
}
