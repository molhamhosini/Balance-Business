<?php

namespace App\Filament\Admin\Resources\VoucherResource\Pages;

use App\Filament\Admin\Resources\VoucherResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListVouchers extends ListRecords
{
    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('newReceipt')->label(__('New receipt'))->color('success')->icon('heroicon-o-arrow-down-tray')
                ->url(fn () => VoucherResource::getUrl('create', ['type' => 'receipt']))->visible(fn () => VoucherResource::canCreate()),
            Actions\Action::make('newPayment')->label(__('New payment'))->color('danger')->icon('heroicon-o-arrow-up-tray')
                ->url(fn () => VoucherResource::getUrl('create', ['type' => 'payment']))->visible(fn () => VoucherResource::canCreate()),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'receipt' => Tab::make(__('Receipts'))->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'receipt')),
            'payment' => Tab::make(__('Payments'))->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'payment')),
            'draft' => Tab::make(__('To post'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft'))
                ->badge(fn () => VoucherResource::getEloquentQuery()->where('status', 'draft')->count() ?: null)->badgeColor('warning'),
        ];
    }
}
