<?php

namespace App\Filament\Admin\Resources\FiscalYearResource\Pages;

use App\Filament\Admin\Resources\FiscalYearResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFiscalYears extends ListRecords
{
    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
