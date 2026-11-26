<?php

namespace App\Filament\Admin\Resources\Base\PartyPages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

abstract class ListParties extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
