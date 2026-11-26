<?php

namespace App\Filament\Admin\Resources\Base\InvoicePages;

use App\Exceptions\AccountingException;
use App\Models\Invoice;
use App\Services\Accounting\InvoiceService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

abstract class CreateInvoice extends CreateRecord
{
    protected function handleRecordCreation(array $data): Model
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);
        if (($data['payment'] ?? 'credit') !== 'cash') {
            $data['cash_account_id'] = null;
        }
        try {
            return app(InvoiceService::class)->saveDraft(new Invoice($data + ['company_id' => Filament::getTenant()->id]), $lines, auth()->user());
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->persistent()->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
