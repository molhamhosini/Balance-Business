<?php

namespace App\Filament\Admin\Resources\TaxCodeResource\Pages;

use App\Filament\Admin\Resources\TaxCodeResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTaxCodes extends ManageRecords
{
    protected static string $resource = TaxCodeResource::class;

    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
