<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Resources\ExchangeRateResource\Pages;
use App\Models\ExchangeRate;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Exchange rates by date (AED per unit); a document uses the latest rate on or before its date. */
class ExchangeRateResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = ExchangeRate::class;
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?int $navigationSort = 8;
    protected static ?string $viewPermission = Perm::ENTRIES_VIEW;
    protected static ?string $createPermission = Perm::ACCOUNTS_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getModelLabel(): string { return __('Exchange rate'); }
    public static function getPluralModelLabel(): string { return __('Exchange rates'); }

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\Select::make('currency')->label(__('Currency'))->required()->searchable()
                ->options(fn () => collect(ExchangeRate::CURRENCIES)->mapWithKeys(fn ($l, $k) => [$k => $k.' — '.__($l)])->all()),
            Forms\Components\DatePicker::make('date')->label(__('Date'))->required()->default(now())->native(false)->displayFormat('Y-m-d'),
            Forms\Components\TextInput::make('rate')->label(__('AED per 1 unit'))->numeric()->required()->minValue(0.000001),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('date', 'desc')->columns([
            Tables\Columns\TextColumn::make('currency')->label(__('Currency'))->badge()->searchable(),
            Tables\Columns\TextColumn::make('date')->label(__('Date'))->date('Y-m-d')->sortable(),
            Tables\Columns\TextColumn::make('rate')->label(__('AED per 1 unit'))->alignEnd(),
        ])->filters([
            Tables\Filters\SelectFilter::make('currency')->label(__('Currency'))->options(fn () => collect(ExchangeRate::CURRENCIES)->mapWithKeys(fn ($l, $k) => [$k => $k])->all()),
        ])->actions([Tables\Actions\EditAction::make()->iconButton(), Tables\Actions\DeleteAction::make()->iconButton()]);
    }

    public static function getPages(): array { return ['index' => Pages\ManageExchangeRates::route('/')]; }
}
