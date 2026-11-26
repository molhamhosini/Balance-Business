<?php

namespace App\Filament\Admin\Resources\ChequeResource\Pages;

use App\Filament\Admin\Resources\ChequeResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCheques extends ListRecords
{
    protected static string $resource = ChequeResource::class;

    protected function getHeaderActions(): array
    {
        $can = \App\Filament\Admin\Resources\VoucherResource::canCreate();

        return [
            \Filament\Actions\Action::make('newReceived')->label(__('New received cheque'))->icon('heroicon-o-arrow-down-tray')->color('success')->visible($can)
                ->url(\App\Filament\Admin\Resources\VoucherResource::getUrl('create', ['type' => 'receipt', 'method' => 'cheque'])),
            \Filament\Actions\Action::make('newIssued')->label(__('New issued cheque'))->icon('heroicon-o-arrow-up-tray')->color('warning')->visible($can)
                ->url(\App\Filament\Admin\Resources\VoucherResource::getUrl('create', ['type' => 'payment', 'method' => 'cheque'])),
        ];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open'))->modifyQueryUsing(fn (Builder $query) => $query->whereIn('cheque_status', ['in_box', 'issued'])),
            'received' => Tab::make(__('Received'))->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'receipt')),
            'issued' => Tab::make(__('Issued'))->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'payment')),
            'all' => Tab::make(__('All')),
        ];
    }
}
