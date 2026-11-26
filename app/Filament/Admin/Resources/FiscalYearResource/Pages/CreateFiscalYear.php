<?php

namespace App\Filament\Admin\Resources\FiscalYearResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\FiscalYearResource;
use App\Services\Accounting\PeriodService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateFiscalYear extends CreateRecord
{
    protected static string $resource = FiscalYearResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PeriodService::class)->createYear(Filament::getTenant(), $data['start_date'], $data['name'] ?: null);
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
            throw new Halt;
        }
    }
}
