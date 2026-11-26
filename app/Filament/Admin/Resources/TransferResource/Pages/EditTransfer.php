<?php

namespace App\Filament\Admin\Resources\TransferResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\TransferResource;
use App\Services\Accounting\BankingService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditTransfer extends EditRecord
{
    protected static string $resource = TransferResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(BankingService::class)->saveTransfer($record->fill($data), auth()->user());
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getHeaderActions(): array { return [Actions\ViewAction::make(), Actions\DeleteAction::make()->label(__('Delete draft'))]; }

    protected function getRedirectUrl(): string { return static::getResource()::getUrl('view', ['record' => $this->record]); }
}
