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
        ];
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
