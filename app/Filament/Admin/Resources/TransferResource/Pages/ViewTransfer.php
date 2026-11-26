<?php

namespace App\Filament\Admin\Resources\TransferResource\Pages;

use App\Filament\Admin\Resources\TransferResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTransfer extends ViewRecord
{
    protected static string $resource = TransferResource::class;

    public function getTitle(): string { return __('Cash transfer').' '.$this->record->display_number; }

    protected function getHeaderActions(): array
    {
        return array_merge(TransferResource::headerActions(), [Actions\EditAction::make()->visible(fn () => TransferResource::canEdit($this->record))]);
    }

    public function refreshRecord(): void { $this->record = $this->record->fresh(); }
}
