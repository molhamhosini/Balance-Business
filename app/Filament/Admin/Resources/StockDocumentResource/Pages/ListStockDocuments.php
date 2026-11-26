<?php

namespace App\Filament\Admin\Resources\StockDocumentResource\Pages;

use App\Filament\Admin\Resources\StockDocumentResource;
use App\Models\StockDocument;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockDocuments extends ListRecords
{
    protected static string $resource = StockDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\ActionGroup::make(collect(StockDocument::TYPES)->map(fn ($l, $k) => Actions\Action::make('new_'.$k)->label(__($l))
            ->url(StockDocumentResource::getUrl('create', ['type' => $k])))->values()->all())
            ->label(__('New document'))->icon('heroicon-o-plus')->button()->visible(StockDocumentResource::canCreate())];
    }
}
