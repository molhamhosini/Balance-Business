<?php

namespace App\Filament\Admin\Resources\Base\InvoicePages;

use App\Exceptions\AccountingException;
use App\Services\Accounting\InvoiceService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

abstract class EditInvoice extends EditRecord
{
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->record->lines->map(fn ($l) => $l->only(['account_id', 'description', 'quantity', 'unit', 'unit_price', 'tax_code_id']))->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);
        if (($data['payment'] ?? 'credit') !== 'cash') {
            $data['cash_account_id'] = null;
        }
        try {
            $record->fill($data);

            return app(InvoiceService::class)->saveDraft($record, $lines, auth()->user());
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->persistent()->send();
            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()->label(__('Delete draft'))->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
