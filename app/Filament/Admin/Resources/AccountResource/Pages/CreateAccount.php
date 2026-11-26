<?php

namespace App\Filament\Admin\Resources\AccountResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\AccountResource;
use App\Models\Account;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;

    public function mount(): void
    {
        parent::mount();
        if ($parent = Account::find(request('parent'))) {
            $this->form->fill(['parent_id' => $parent->id, 'code' => Account::suggestCode($parent), 'nature' => $parent->nature, 'is_active' => true]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data + ['source' => 'user']);
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
