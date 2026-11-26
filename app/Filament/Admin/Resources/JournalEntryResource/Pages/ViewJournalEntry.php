<?php

namespace App\Filament\Admin\Resources\JournalEntryResource\Pages;

use App\Filament\Admin\Resources\JournalEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    public function getTitle(): string
    {
        return __('Journal entry').' '.$this->record->display_number;
    }

    protected function getHeaderActions(): array
    {
        return [
            JournalEntryResource::postAction(),
            Actions\EditAction::make()->visible(fn () => JournalEntryResource::canEdit($this->record)),
            JournalEntryResource::reverseAction(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->record->fresh();
    }
}
