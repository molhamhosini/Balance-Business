<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\JournalEntryResource;
use App\Filament\Admin\Resources\VoucherResource;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Voucher;
use App\Services\Accounting\ReportService;
use App\Support\Money;
use App\Support\Perm;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AccountingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()->canAny([Perm::REPORTS_VIEW, Perm::ENTRIES_VIEW]);
    }

    protected function getStats(): array
    {
        $c = Filament::getTenant();
        $svc = app(ReportService::class);
        $today = now()->toDateString();
        $sums = $svc->sums($c, null, $today);
        $cash = Account::where('company_id', $c->id)->where(fn ($q) => $q->where('is_cash', true)->orWhere('is_bank', true))->pluck('id');
        $liquid = $cash->sum(fn ($id) => ($sums[$id]['d'] ?? 0) - ($sums[$id]['c'] ?? 0));
        $is = $svc->incomeStatement($c, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());
        $vat = $svc->vat201($c, now()->firstOfQuarter()->toDateString(), now()->lastOfQuarter()->toDateString());
        $drafts = JournalEntry::where('company_id', $c->id)->where('status', 'draft')->count() + Voucher::where('company_id', $c->id)->where('status', 'draft')->count();

        return [
            Stat::make(__('Cash & bank'), Money::format($liquid))->description(__('All cash boxes and banks today'))->icon('heroicon-o-banknotes'),
            Stat::make(__('Revenue this month'), Money::format($is['totals']['revenue']))->description(__('Net profit').': '.Money::format($is['net']))
                ->color($is['net'] >= 0 ? 'success' : 'danger')->icon('heroicon-o-arrow-trending-up'),
            Stat::make(__('VAT due this quarter'), Money::format($vat['14']['vat']))->description(__('Output').' '.Money::format($vat['12']['vat']).' − '.__('Input').' '.Money::format($vat['13']['vat']))->icon('heroicon-o-receipt-percent'),
            Stat::make(__('Documents to post'), (string) $drafts)->description(__('Draft entries and vouchers'))->color($drafts ? 'warning' : 'success')
                ->icon('heroicon-o-clock')->url(VoucherResource::getUrl('index', ['activeTab' => 'draft'])),
        ];
    }
}
