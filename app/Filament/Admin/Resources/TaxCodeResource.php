<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Resources\TaxCodeResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\TaxCode;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TaxCodeResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = TaxCode::class;
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?int $navigationSort = 4;
    protected static ?string $viewPermission = Perm::ACCOUNTS_MANAGE;
    protected static ?string $createPermission = Perm::ACCOUNTS_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getModelLabel(): string { return __('Tax code'); }
    public static function getPluralModelLabel(): string { return __('Tax codes'); }

    public static function canDelete(Model $record): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\TextInput::make('code')->label(__('Code'))->required()->maxLength(10),
            Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required(),
            Forms\Components\TextInput::make('name_en')->label(__('English name')),
            Forms\Components\Select::make('type')->label(__('Treatment'))->options(fn () => collect(TaxCode::TYPES)->map(fn ($l) => __($l))->all())->required(),
            Forms\Components\TextInput::make('rate')->label(__('Rate %'))->numeric()->required()->default(5),
            Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true)->inline(false),
            Forms\Components\Select::make('output_account_id')->label(__('Output VAT account'))->options(fn () => Options::postableAccounts())->searchable(),
            Forms\Components\Select::make('input_account_id')->label(__('Input VAT account'))->options(fn () => Options::postableAccounts())->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label(__('Code'))->weight('bold'),
            Tables\Columns\TextColumn::make('name_ar')->label(__('Name')),
            Tables\Columns\TextColumn::make('type')->label(__('Treatment'))->formatStateUsing(fn ($state) => __(TaxCode::TYPES[$state] ?? $state)),
            Tables\Columns\TextColumn::make('rate')->label(__('Rate %')),
            Tables\Columns\TextColumn::make('outputAccount.code')->label(__('Output VAT account')),
            Tables\Columns\TextColumn::make('inputAccount.code')->label(__('Input VAT account')),
            Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTaxCodes::route('/')];
    }
}
