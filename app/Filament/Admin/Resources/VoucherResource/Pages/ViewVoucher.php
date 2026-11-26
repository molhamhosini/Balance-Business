<?php

namespace App\Filament\Admin\Resources\VoucherResource\Pages;

use App\Filament\Admin\Resources\VoucherResource;
use App\Models\Voucher;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewVoucher extends ViewRecord
{
    protected static string $resource = VoucherResource::class;

    public function getTitle(): string
    {
        return __(Voucher::TYPES[$this->record->type]).' '.$this->record->display_number;
    }

    protected function getHeaderActions(): array
    {
        return [
            VoucherResource::postAction(),
            Actions\EditAction::make()->visible(fn () => VoucherResource::canEdit($this->record)),
            VoucherResource::cancelAction(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->record->fresh();
    }
}
