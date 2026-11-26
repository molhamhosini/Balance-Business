<?php

namespace App\Filament\Admin\Resources\CostCenterResource\Pages;

use App\Filament\Admin\Resources\CostCenterResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageCostCenters extends ManageRecords
{
    protected static string $resource = CostCenterResource::class;

    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
