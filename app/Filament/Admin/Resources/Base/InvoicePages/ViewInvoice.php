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
            ...$r::quotationActions(),
            $r::registerPaymentAction(),
            $r::writeOffAction(),
            Actions\EditAction::make()->visible(fn () => $r::canEdit($this->record)),
            $r::creditNoteAction(),
            Actions\Action::make('downloadPdf')->label(__('Download PDF'))->icon('heroicon-o-document-arrow-down')->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print (\App\Services\Documents\InvoiceExport::invoicePdf($this->record)),
                    \App\Services\Documents\InvoiceExport::fileName($this->record), ['Content-Type' => 'application/pdf'])),
            $r::sendActions(),
            $r::einvoiceActions(),
            $r::cancelAction(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->record->fresh();
    }
}
