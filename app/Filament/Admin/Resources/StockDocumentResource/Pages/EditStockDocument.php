<?php

namespace App\Filament\Admin\Resources\StockDocumentResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\StockDocumentResource;
use App\Services\Accounting\InventoryService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditStockDocument extends EditRecord
{
    protected static string $resource = StockDocumentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->record->lines->map(fn ($l) => $l->only(['item_id', 'quantity', 'unit_cost', 'invoice_id']))->all();
        $data['date'] = $this->record->date->toDateString();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);
        try {
            return app(InventoryService::class)->saveDocument($record->fill($data), $lines, auth()->user());
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getHeaderActions(): array { return [Actions\ViewAction::make(), Actions\DeleteAction::make()->label(__('Delete draft'))]; }

    protected function getRedirectUrl(): string { return static::getResource()::getUrl('view', ['record' => $this->record]); }
}
