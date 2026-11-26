<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\RoleResource\Pages;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/** Editable roles (requirements §6: roles are configurable). */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;
    protected static bool $isScopedToTenant = false;
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getModelLabel(): string { return __('Role'); }
    public static function getPluralModelLabel(): string { return __('Roles & permissions'); }

    public static function canViewAny(): bool { return auth()->user()->can(Perm::USERS_MANAGE); }
    public static function canCreate(): bool { return static::canViewAny(); }
    public static function canEdit(Model $record): bool { return static::canViewAny(); }
    public static function canDelete(Model $record): bool { return static::canViewAny() && ! isset(Perm::ROLES[$record->name]); }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('Name'))->required()->disabled(fn (?Role $record) => $record && isset(Perm::ROLES[$record->name])),
            Forms\Components\CheckboxList::make('permissions')->label(__('Permissions'))->relationship('permissions', 'name')
                ->getOptionLabelFromRecordUsing(fn ($p) => __(Perm::LABELS[$p->name] ?? $p->name))->columns(2)->bulkToggleable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label(__('Name'))->formatStateUsing(fn ($state) => __(Perm::ROLES[$state]['label'] ?? $state))->weight('bold'),
            Tables\Columns\TextColumn::make('permissions_count')->counts('permissions')->label(__('Permissions')),
            Tables\Columns\TextColumn::make('users_count')->counts('users')->label(__('Users')),
        ])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageRoles::route('/')];
    }
}
