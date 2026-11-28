<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Resources\WarehouseResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\Warehouse;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WarehouseResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Warehouse::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?int $navigationSort = 9;
    protected static ?string $viewPermission = Perm::INVENTORY_VIEW;
    protected static ?string $createPermission = Perm::INVENTORY_POST;

    public static function getNavigationGroup(): ?string { return __('Inventory'); }
    public static function getModelLabel(): string { return __('Warehouse'); }
    public static function getPluralModelLabel(): string { return __('Warehouses'); }

    public static function canDelete(Model $record): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('code')->label(__('Code'))->required()->maxLength(20)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('company_id', Options::companyId())),
            Forms\Components\Select::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches()),
            Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required(),
            Forms\Components\TextInput::make('name_en')->label(__('English name')),
            Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label(__('Code'))->weight('bold'),
            Tables\Columns\TextColumn::make('name_ar')->formatStateUsing(\App\Support\Localized::name())->label(__('Name')),
            Tables\Columns\TextColumn::make('branch.name_ar')->formatStateUsing(\App\Support\Localized::name('branch'))->label(__('Branch'))->placeholder('—'),
            Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
        ])->actions([Tables\Actions\EditAction::make()->iconButton()]);
    }

    public static function getPages(): array { return ['index' => Pages\ManageWarehouses::route('/')]; }
}
