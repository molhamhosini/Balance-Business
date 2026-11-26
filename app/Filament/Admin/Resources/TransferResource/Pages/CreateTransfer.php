<?php

namespace App\Filament\Admin\Resources\TransferResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\TransferResource;
use App\Models\Transfer;
use App\Services\Accounting\BankingService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateTransfer extends CreateRecord
{
    protected static string $resource = TransferResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(BankingService::class)->saveTransfer(new Transfer($data + ['company_id' => Filament::getTenant()->id]), auth()->user());
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string { return static::getResource()::getUrl('view', ['record' => $this->record]); }
}
