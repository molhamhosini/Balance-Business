<?php

namespace App\Filament\Admin\Resources\Base\PartyPages;

use App\Filament\Admin\Pages\PartyStatement;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

abstract class ViewParty extends ViewRecord
{
    protected function getHeaderActions(): array
    {
        $inv = static::getResource()::invoiceResource();
        $kind = static::getResource()::partyType() === 'customer' ? 'sale' : 'purchase';

        return [
            Actions\Action::make('newInvoice')->label($kind === 'sale' ? __('New invoice') : __('New bill'))->icon('heroicon-o-plus')
                ->url(fn () => $inv::getUrl('create', ['kind' => $kind, 'party' => $this->record->id]))->visible(fn () => $inv::canCreate()),
            Actions\Action::make('statement')->label(__('Statement'))->icon('heroicon-o-document-magnifying-glass')->color('gray')
                ->url(fn () => PartyStatement::getUrl(['party' => $this->record->id])),
            Actions\EditAction::make(),
        ];
    }
}
