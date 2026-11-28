<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\ChequeResource;
use App\Filament\Admin\Resources\SalesInvoiceResource;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Support\Money;
use App\Support\Perm;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/** Dashboard "Due transactions": overdue customer invoices, received cheques due for collection, issued cheques due to be paid. */
class DueTransactions extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getHeading(): ?string { return __('Due transactions'); }

    public static function canView(): bool
    {
        return auth()->user()->canAny([Perm::REPORTS_VIEW, Perm::INVOICES_VIEW, Perm::VOUCHERS_VIEW]);
    }

    /** Received cheques still in the box or deposited (not cleared/returned) whose due date has come. */
    public static function dueReceived(Builder $q): Builder
    {
        return $q->where('type', 'receipt')->whereIn('cheque_status', ['in_box', 'deposited'])->whereDate('cheque_due_date', '<=', now()->toDateString());
    }

    /** Issued cheques not yet cleared by the bank whose due date has come. */
    public static function dueIssued(Builder $q): Builder
    {
        return $q->where('type', 'payment')->where('cheque_status', 'issued')->whereDate('cheque_due_date', '<=', now()->toDateString());
    }

    protected function getStats(): array
    {
        $c = Filament::getTenant();
        $overdue = Invoice::where('company_id', $c->id)->where('kind', 'sale')->where('status', 'posted')->where('payment', 'credit')
            ->whereDate('due_date', '<', now()->toDateString())->get()->filter(fn ($i) => $i->openCents() > 0);
        $customers = $overdue->pluck('party_id')->unique()->count();
        $vq = fn () => Voucher::where('company_id', $c->id)->where('status', 'posted')->where('payment_method', 'cheque');
        $rec = static::dueReceived($vq())->get();
        $iss = static::dueIssued($vq())->get();
        $sum = fn ($rows) => Money::format($rows->sum(fn ($v) => Money::toCents($v->total)));

        return [
            Stat::make(__('Customer balances due'), Money::format($overdue->sum(fn ($i) => $i->openCents())))
                ->description(__(':n overdue invoices · :c customers', ['n' => $overdue->count(), 'c' => $customers]))
                ->color($overdue->isEmpty() ? 'success' : 'danger')->icon('heroicon-o-user-group')
                ->url(SalesInvoiceResource::getUrl('index', ['tableFilters' => ['overdue' => ['isActive' => true]]])),
            Stat::make(__('Received cheques due'), $sum($rec))
                ->description(__(':n cheques due for collection', ['n' => $rec->count()]))
                ->color($rec->isEmpty() ? 'success' : 'warning')->icon('heroicon-o-arrow-down-tray')
                ->url(ChequeResource::getUrl('index', ['activeTab' => 'due_received'])),
            Stat::make(__('Issued cheques due'), $sum($iss))
                ->description(__(':n cheques due for payment', ['n' => $iss->count()]))
                ->color($iss->isEmpty() ? 'success' : 'warning')->icon('heroicon-o-arrow-up-tray')
                ->url(ChequeResource::getUrl('index', ['activeTab' => 'due_issued'])),
        ];
    }
}
