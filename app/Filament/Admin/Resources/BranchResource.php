<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Resources\BranchResource\Pages;
use App\Models\Branch;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BranchResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Branch::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?int $navigationSort = 3;
    protected static ?string $viewPermission = Perm::SETTINGS_MANAGE;
    protected static ?string $createPermission = Perm::SETTINGS_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getModelLabel(): string { return __('Branch'); }
    public static function getPluralModelLabel(): string { return __('Branches'); }

    public static function canDelete(Model $record): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\TextInput::make('code')->label(__('Code'))->required()->maxLength(20),
            Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required(),
            Forms\Components\TextInput::make('name_en')->label(__('English name')),
            Forms\Components\Select::make('emirate')->label(__('Emirate'))->options(Branch::EMIRATES)->default('AUH')->required()
                ->helperText(__('Decides the VAT 201 box (1.1 to 1.7) for standard-rated sales.')),
            Forms\Components\Select::make('activity')->label(__('Activity'))->options(['slaughterhouse' => __('Slaughterhouse'), 'restaurant' => __('Restaurant'), 'other' => __('Other')]),
            Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label(__('Code')),
            Tables\Columns\TextColumn::make('name_ar')->label(__('Name')),
            Tables\Columns\TextColumn::make('emirate')->label(__('Emirate'))->formatStateUsing(fn ($state) => Branch::EMIRATES[$state] ?? $state),
            Tables\Columns\TextColumn::make('activity')->label(__('Activity')),
            Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBranches::route('/'),
        ];
    }
}
