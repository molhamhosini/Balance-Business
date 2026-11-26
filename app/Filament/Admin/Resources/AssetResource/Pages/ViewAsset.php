<?php

namespace App\Filament\Admin\Resources\AssetResource\Pages;

use App\Filament\Admin\Resources\AssetResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    public function getTitle(): string { return $this->record->label; }

    protected function getHeaderActions(): array
    {
        return [AssetResource::disposeAction(), Actions\EditAction::make()->visible(fn () => $this->record->status !== 'disposed')];
    }

    public function refreshRecord(): void { $this->record = $this->record->fresh(); }
}
