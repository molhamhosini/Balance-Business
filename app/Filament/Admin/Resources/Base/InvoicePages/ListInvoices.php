<?php

namespace App\Filament\Admin\Resources\Base\InvoicePages;

use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

abstract class ListInvoices extends ListRecords
{
    protected function getHeaderActions(): array
    {
        $r = static::getResource();
        $side = $r::side();

        return [
            Actions\Action::make('newQuotation')->label(__('New quotation'))->icon('heroicon-o-document-duplicate')->color('gray')
                ->url(fn () => $r::getUrl('create', ['kind' => 'quotation']))->visible(fn () => $side === 'sale' && $r::canCreate()),
            Actions\Action::make('new')->label($side === 'sale' ? __('New invoice') : __('New bill'))->icon('heroicon-o-plus')
                ->url(fn () => $r::getUrl('create', ['kind' => $side]))->visible(fn () => $r::canCreate()),
            Actions\Action::make('newReturn')->label($side === 'sale' ? __('New credit note') : __('New debit note'))->icon('heroicon-o-receipt-refund')->color('warning')
                ->url(fn () => $r::getUrl('create', ['kind' => $side.'_return']))->visible(fn () => $r::canCreate()),
            Actions\ActionGroup::make([
                Actions\Action::make('exportPdf')->label(__('Download PDF'))->icon('heroicon-o-document-arrow-down')
                    ->action(fn () => $this->downloadList('pdf')),
                Actions\Action::make('exportExcel')->label(__('Export to Excel'))->icon('heroicon-o-table-cells')
                    ->action(fn () => $this->downloadList('xlsx')),
            ])->label(__('Export'))->icon('heroicon-o-arrow-down-tray')->button()->color('gray'),
        ];
    }

    /** Exports exactly what the list shows: the active tab, filters, search and sort. */
    public function downloadList(string $format)
    {
        $r = static::getResource();
        $company = \Filament\Facades\Filament::getTenant();
        $invoices = $this->getFilteredSortedTableQuery()->with('party')->get();
        $tab = $this->activeTab ? ($this->getTabs()[$this->activeTab] ?? null)?->getLabel() : null;
        $title = $r::getPluralModelLabel().($tab && $this->activeTab !== 'all' ? ' — '.$tab : '');
        $dates = $invoices->pluck('date')->filter();
        $subtitle = $dates->isEmpty() ? '' : $dates->min()->format('Y-m-d').' → '.$dates->max()->format('Y-m-d');
        [$headers, $rows] = \App\Services\Documents\InvoiceExport::listRows($invoices, fn ($k) => $r::kindLabel($k), $r::statusLabels(),
            $r::side() === 'sale' ? __('Customer') : __('Supplier'));
        $svc = \App\Services\Documents\InvoiceExport::class;
        $bytes = $format === 'pdf' ? $svc::listPdf($company, $title, $subtitle, $headers, $rows) : $svc::listExcel($company, $title, $subtitle, $headers, $rows);
        $file = ($r::side() === 'sale' ? 'sales-invoices' : 'purchase-bills').'-'.now()->format('Y-m-d').'.'.$format;

        return response()->streamDownload(fn () => print ($bytes), $file, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function getTabs(): array
    {
        $r = static::getResource();
        $side = $r::side();
        $open = fn (Builder $q) => $q->where('status', 'posted')->where('payment', 'credit')
            ->whereRaw('total > (select coalesce(sum(a.amount),0) from allocations a where a.invoice_id = invoices.id)');

        $quotations = $side === 'sale' ? ['quotations' => Tab::make(__('Quotations'))->modifyQueryUsing(fn (Builder $query) => $query->where('kind', 'quotation'))
            ->badge(fn () => $r::getEloquentQuery()->where('kind', 'quotation')->whereIn('status', ['draft', 'sent'])->count() ?: null)] : [];

        return [
            'all' => Tab::make(__('All')),
            'invoices' => Tab::make($side === 'sale' ? __('Invoices') : __('Bills'))->modifyQueryUsing(fn (Builder $query) => $query->where('kind', $side)),
            'returns' => Tab::make($side === 'sale' ? __('Credit notes') : __('Debit notes'))->modifyQueryUsing(fn (Builder $query) => $query->where('kind', $side.'_return')),
            ...$quotations,
            'open' => Tab::make(__('To pay'))->modifyQueryUsing(fn (Builder $query) => $open($query->where('kind', $side))),
            'draft' => Tab::make(__('Drafts'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft'))
                ->badge(fn () => $r::getEloquentQuery()->where('status', 'draft')->count() ?: null)->badgeColor('warning'),
        ];
    }
}
