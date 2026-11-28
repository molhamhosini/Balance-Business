<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Resources\BankStatementResource\Pages;
use App\Models\BankStatement;
use App\Support\Perm;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Bank statements imported from Excel/CSV and reconciled against the bank account's book lines. */
class BankStatementResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = BankStatement::class;
    protected static ?string $navigationIcon = 'heroicon-o-scale';
    protected static ?int $navigationSort = 5;
    protected static ?string $viewPermission = Perm::BANKING_MANAGE;
    protected static ?string $createPermission = Perm::BANKING_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Cash & banks'); }
    public static function getModelLabel(): string { return __('Bank statement'); }
    public static function getPluralModelLabel(): string { return __('Bank reconciliation'); }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return static::allowed(Perm::BANKING_MANAGE) && $record->status === 'open'; }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('date_to', 'desc')->columns([
            Tables\Columns\TextColumn::make('account.name_ar')->formatStateUsing(\App\Support\Localized::name('account'))->label(__('Bank account'))->weight('bold'),
            Tables\Columns\TextColumn::make('date_from')->label(__('From'))->date('Y-m-d'),
            Tables\Columns\TextColumn::make('date_to')->label(__('To'))->date('Y-m-d')->sortable(),
            Tables\Columns\TextColumn::make('closing_balance')->label(__('Statement closing balance'))->numeric(2, locale: 'en')->alignEnd(),
            Tables\Columns\TextColumn::make('progress')->label(__('Matched'))->state(fn (BankStatement $r) => $r->lines()->whereNotNull('journal_line_id')->count().' / '.$r->lines()->count()),
            Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state === 'reconciled' ? __('Reconciled') : __('Open'))
                ->color(fn ($state) => $state === 'reconciled' ? 'success' : 'warning'),
            Tables\Columns\TextColumn::make('file_name')->label(__('File'))->toggleable(isToggledHiddenByDefault: true),
        ])->actions([
            Tables\Actions\Action::make('reconcile')->label(__('Reconcile'))->icon('heroicon-o-scale')->url(fn (BankStatement $r) => static::getUrl('view', ['record' => $r])),
            Tables\Actions\DeleteAction::make()->iconButton(),
        ])->recordUrl(fn (BankStatement $r) => static::getUrl('view', ['record' => $r]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankStatements::route('/'),
            'create' => Pages\ImportBankStatement::route('/import'),
            'view' => Pages\ReconcileBankStatement::route('/{record}'),
        ];
    }
}
