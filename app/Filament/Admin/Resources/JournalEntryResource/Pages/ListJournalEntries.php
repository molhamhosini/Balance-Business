<?php

namespace App\Filament\Admin\Resources\JournalEntryResource\Pages;

use App\Filament\Admin\Resources\JournalEntryResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListJournalEntries extends ListRecords
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label(__('New entry'))];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'draft' => Tab::make(__('Drafts'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft'))
                ->badge(fn () => JournalEntryResource::getEloquentQuery()->where('status', 'draft')->count() ?: null)->badgeColor('warning'),
            'posted' => Tab::make(__('Posted'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'posted')),
            'reversed' => Tab::make(__('Reversed'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'reversed')),
        ];
    }
}
