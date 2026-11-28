<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Resources\AssetCategoryResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\AssetCategory;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AssetCategoryResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = AssetCategory::class;
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?int $navigationSort = 3;
    protected static ?string $viewPermission = Perm::ASSETS_MANAGE;
    protected static ?string $createPermission = Perm::ASSETS_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Fixed assets'); }
    public static function getModelLabel(): string { return __('Asset category'); }
    public static function getPluralModelLabel(): string { return __('Asset categories'); }

    public static function canDelete(Model $record): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required(),
            Forms\Components\TextInput::make('name_en')->label(__('English name')),
            Forms\Components\TextInput::make('useful_life_months')->label(__('Useful life (months)'))->numeric()->minValue(1)->required()->default(60),
            Forms\Components\Select::make('asset_account_id')->label(__('Asset account'))->options(fn () => Options::postableAccounts(fn ($q) => $q->where('class', 'asset')))->searchable()->required(),
            Forms\Components\Select::make('accumulated_account_id')->label(__('Accumulated depreciation account'))->options(fn () => Options::postableAccounts(fn ($q) => $q->where('class', 'asset')))->searchable()->required(),
            Forms\Components\Select::make('expense_account_id')->label(__('Depreciation expense account'))->options(fn () => Options::postableAccounts(fn ($q) => $q->where('class', 'expense')))->searchable()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name_ar')->formatStateUsing(\App\Support\Localized::name())->label(__('Name'))->weight('bold'),
            Tables\Columns\TextColumn::make('useful_life_months')->label(__('Useful life (months)')),
            Tables\Columns\TextColumn::make('assetAccount.label')->label(__('Asset account')),
            Tables\Columns\TextColumn::make('accumulatedAccount.label')->label(__('Accumulated depreciation account'))->toggleable(),
            Tables\Columns\TextColumn::make('expenseAccount.label')->label(__('Depreciation expense account'))->toggleable(),
        ])->actions([Tables\Actions\EditAction::make()->iconButton()]);
    }

    public static function getPages(): array { return ['index' => Pages\ManageAssetCategories::route('/')]; }
}
