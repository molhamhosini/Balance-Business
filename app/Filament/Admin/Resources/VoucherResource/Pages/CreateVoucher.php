<?php

namespace App\Filament\Admin\Resources\VoucherResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\VoucherResource;
use App\Models\Voucher;
use App\Services\Accounting\VoucherService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateVoucher extends CreateRecord
{
    protected static string $resource = VoucherResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(VoucherService::class)->saveDraft(new Voucher($data + ['company_id' => Filament::getTenant()->id]), auth()->user());
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
