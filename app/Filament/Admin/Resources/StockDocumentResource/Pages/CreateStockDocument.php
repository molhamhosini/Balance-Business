<?php

namespace App\Filament\Admin\Resources\StockDocumentResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\StockDocumentResource;
use App\Models\StockDocument;
use App\Services\Accounting\InventoryService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateStockDocument extends CreateRecord
{
    protected static string $resource = StockDocumentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);
        try {
            return app(InventoryService::class)->saveDocument(new StockDocument($data + ['company_id' => Filament::getTenant()->id]), $lines, auth()->user());
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string { return static::getResource()::getUrl('view', ['record' => $this->record]); }
}
