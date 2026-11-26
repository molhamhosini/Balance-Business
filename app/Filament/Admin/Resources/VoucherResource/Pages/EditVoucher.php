<?php

namespace App\Filament\Admin\Resources\VoucherResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\VoucherResource;
use App\Services\Accounting\VoucherService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditVoucher extends EditRecord
{
    protected static string $resource = VoucherResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            $record->fill($data);

            return app(VoucherService::class)->saveDraft($record, auth()->user());
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()->label(__('Delete draft'))->using(function (Model $record) {
                app(VoucherService::class)->deleteDraft($record, auth()->user());

                return true;
            }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
