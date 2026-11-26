<?php

namespace App\Filament\Admin\Resources\AssetCategoryResource\Pages;

use App\Filament\Admin\Resources\AssetCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageAssetCategories extends ManageRecords
{
    protected static string $resource = AssetCategoryResource::class;

    protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
