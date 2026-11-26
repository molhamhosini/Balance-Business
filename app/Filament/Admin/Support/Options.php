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

    public static function costCenters(?int $except = null): array
    {
        return \App\Models\CostCenter::where('company_id', self::companyId())->where('is_active', true)->when($except, fn ($q) => $q->whereKeyNot($except))
            ->orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->id => $c->label])->all();
    }

    public static function warehouses(): array
    {
        return \App\Models\Warehouse::where('company_id', self::companyId())->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => $w->label])->all();
    }

    public static function items(?string $type = null): array
    {
        return \App\Models\Item::where('company_id', self::companyId())->where('is_active', true)->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('code')->get()->mapWithKeys(fn ($i) => [$i->id => $i->label])->all();
    }

    public static function currencies(): array
    {
        return ['AED' => 'AED — '.__('UAE dirham')] + collect(\App\Models\ExchangeRate::CURRENCIES)->mapWithKeys(fn ($l, $k) => [$k => $k.' — '.__($l)])->all();
    }
}
