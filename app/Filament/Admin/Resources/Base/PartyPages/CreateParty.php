<?php

namespace App\Filament\Admin\Resources\Base\PartyPages;

use App\Exceptions\AccountingException;
use App\Services\Accounting\PartyService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

abstract class CreateParty extends CreateRecord
{
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $data['type'] = static::getResource()::partyType();

            return app(PartyService::class)->create(Filament::getTenant(), $data, auth()->user());
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
