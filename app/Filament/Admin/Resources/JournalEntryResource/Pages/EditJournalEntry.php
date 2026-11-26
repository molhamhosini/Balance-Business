<?php

namespace App\Filament\Admin\Resources\JournalEntryResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\JournalEntryResource;
use App\Services\Accounting\PostingService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditJournalEntry extends EditRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->record->lines->map(fn ($l) => $l->only(['account_id', 'description', 'debit', 'credit', 'tax_code_id']))->all();
        $data['date'] = $this->record->date->toDateString();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $lines = $data['lines'] ?? [];
        unset($data['lines']);
        try {
            return app(PostingService::class)->updateDraft($record, $data, $lines, auth()->user());
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()->label(__('Delete draft'))
                ->using(function (Model $record) {
                    app(PostingService::class)->deleteDraft($record, auth()->user());

                    return true;
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
