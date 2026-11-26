<?php

namespace App\Filament\Admin\Resources\Base\InvoicePages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

abstract class ViewInvoice extends ViewRecord
{
    public function getTitle(): string
    {
        return static::getResource()::kindLabel($this->record->kind).' '.$this->record->display_number;
    }

    protected function getHeaderActions(): array
    {
        $r = static::getResource();

        return [
            $r::postAction(),
            $r::registerPaymentAction(),
            $r::writeOffAction(),
            Actions\EditAction::make()->visible(fn () => $r::canEdit($this->record)),
            $r::creditNoteAction(),
            $r::einvoiceActions(),
            $r::cancelAction(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->record->fresh();
    }
}
