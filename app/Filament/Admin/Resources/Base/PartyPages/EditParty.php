<?php

namespace App\Filament\Admin\Resources\Base\PartyPages;

use App\Models\AuditLog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

abstract class EditParty extends EditRecord
{
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $before = $record->only(array_keys($data));
        $record->update($data);
        AuditLog::record('updated', $record, $before, $record->only(array_keys($data)));

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
