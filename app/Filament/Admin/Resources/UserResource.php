<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\UserResource\Pages;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Perm;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static bool $isScopedToTenant = false;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getModelLabel(): string { return __('User'); }
    public static function getPluralModelLabel(): string { return __('Users'); }

    public static function canViewAny(): bool { return auth()->user()->can(Perm::USERS_MANAGE); }
    public static function canCreate(): bool { return static::canViewAny(); }
    public static function canEdit(Model $record): bool { return static::canViewAny(); }
    public static function canDelete(Model $record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('companies', fn ($q) => $q->whereKey(Filament::getTenant()->id));
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')->label(__('Name'))->required(),
            Forms\Components\TextInput::make('email')->label(__('Email'))->email()->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('password')->label(__('Password'))->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->minLength(8),
            Forms\Components\Select::make('locale')->label(__('Language'))->options(['ar' => 'العربية', 'en' => 'English'])->default('ar'),
            Forms\Components\Select::make('roles')->label(__('Roles'))->multiple()->relationship('roles', 'name')->preload()
                ->getOptionLabelFromRecordUsing(fn (Role $r) => __(Perm::ROLES[$r->name]['label'] ?? $r->name)),
            Forms\Components\Select::make('companies')->label(__('Companies'))->multiple()->relationship('companies', 'name_ar')->preload()
                ->default(fn () => [Filament::getTenant()->id]),
            Forms\Components\Select::make('branches')->label(__('Restrict to branches'))->multiple()->relationship('branches', 'name_ar')->preload()
                ->helperText(__('Leave empty to allow all branches.')),
            Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label(__('Name'))->searchable()->weight('bold'),
            Tables\Columns\TextColumn::make('email')->label(__('Email'))->searchable(),
            Tables\Columns\TextColumn::make('roles.name')->label(__('Roles'))->badge()->formatStateUsing(fn ($state) => __(Perm::ROLES[$state]['label'] ?? $state)),
            Tables\Columns\TextColumn::make('branches.name_ar')->label(__('Branches'))->badge()->color('gray')->placeholder(__('All')),
            Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUsers::route('/')];
    }
}
