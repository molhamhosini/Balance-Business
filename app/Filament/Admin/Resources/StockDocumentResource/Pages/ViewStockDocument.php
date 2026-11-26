<?php

namespace App\Filament\Admin\Resources\StockDocumentResource\Pages;

use App\Filament\Admin\Resources\StockDocumentResource;
use App\Models\StockDocument;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewStockDocument extends ViewRecord
{
    protected static string $resource = StockDocumentResource::class;

    public function getTitle(): string { return __(StockDocument::TYPES[$this->record->type]).' '.$this->record->display_number; }

    protected function getHeaderActions(): array
    {
        return array_merge(StockDocumentResource::headerActions(), [Actions\EditAction::make()->visible(fn () => StockDocumentResource::canEdit($this->record))]);
    }

    public function refreshRecord(): void { $this->record = $this->record->fresh(); }
}
