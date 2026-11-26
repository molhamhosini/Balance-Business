<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Filament\Admin\Pages\Ledger;
use App\Filament\Admin\Resources\AccountResource\Pages;
use App\Models\Account;
use App\Support\Perm;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AccountResource extends Resource
{
    use ChecksPermissions, NotifiesAccountingErrors;

    protected static ?string $model = Account::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 3;
    protected static ?string $recordTitleAttribute = 'label';
    protected static ?string $viewPermission = null;
    protected static ?string $createPermission = Perm::ACCOUNTS_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Accounting'); }
    public static function getModelLabel(): string { return __('Account'); }
    public static function getPluralModelLabel(): string { return __('Chart of accounts'); }

    public static function canViewAny(): bool
    {
        return auth()->user()->canAny([Perm::ACCOUNTS_MANAGE, Perm::REPORTS_VIEW, Perm::ENTRIES_VIEW]);
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can(Perm::ACCOUNTS_MANAGE) && ! $record->lines()->exists() && ! $record->children()->exists() && ! $record->system_key;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\Select::make('parent_id')->label(__('Parent account'))
                    ->options(fn () => Account::where('company_id', Filament::getTenant()->id)->where('level', '<', 4)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => str_repeat('— ', $a->level - 1).$a->label]))
                    ->searchable()->required()->live()->disabledOn('edit')
                    ->afterStateUpdated(fn (Set $set, $state) => $state ? $set('code', Account::suggestCode(Account::find($state))) : null),
                Forms\Components\TextInput::make('code')->label(__('Code'))->required()->disabledOn('edit')
                    ->rule(fn (Get $get) => function ($attr, $value, $fail) use ($get) {
                        $parent = Account::find($get('parent_id'));
                        if ($parent && ! str_starts_with($value, rtrim($parent->code, '0') ?: $parent->code[0])) {
                            $fail(__('The code must start with the parent code.'));
                        }
                        if (Account::where('company_id', Filament::getTenant()->id)->where('code', $value)->exists()) {
                            $fail(__('This code already exists.'));
                        }
                    }),
                Forms\Components\Select::make('nature')->label(__('Normal balance'))->options(['debit' => __('Debit'), 'credit' => __('Credit')])->required()
                    ->default(fn (Get $get) => Account::find($get('parent_id'))?->nature),
                Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required(),
                Forms\Components\TextInput::make('name_en')->label(__('English name')),
                Forms\Components\TextInput::make('statement_line')->label(__('Financial statement line')),
            ]),
            Forms\Components\Section::make(__('Options'))->columns(4)->schema([
                Forms\Components\Toggle::make('is_cash')->label(__('Cash box (cannot go negative)')),
                Forms\Components\Toggle::make('is_bank')->label(__('Bank account')),
                Forms\Components\Toggle::make('is_non_deductible')->label(__('Not deductible for corporate tax')),
                Forms\Components\Toggle::make('is_related_party')->label(__('Related party')),
                Forms\Components\Select::make('system_key')->label(__('System binding'))->options(fn () => collect(Account::SYSTEM_KEYS)->map(fn ($l) => __($l))->all())->placeholder('—')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('company_id', Filament::getTenant()->id)),
                Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true),
                Forms\Components\Textarea::make('notes')->label(__('Notes'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $balances = fn () => once(fn () => DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', Filament::getTenant()->id)->whereIn('e.status', ['posted', 'reversed'])
            ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) - SUM(l.credit) b')->pluck('b', 'account_id'));

        return $table
            ->defaultSort('code')
            ->paginated([50, 100, 'all'])->defaultPaginationPageOption(100)
            ->columns([
                Tables\Columns\TextColumn::make('code')->label(__('Code'))->searchable()->sortable()
                    ->formatStateUsing(fn ($state, Account $r) => str_repeat('    ', $r->level - 1).$state)
                    ->weight(fn (Account $r) => $r->is_postable ? 'normal' : 'bold')->extraAttributes(['style' => 'white-space:pre']),
                Tables\Columns\TextColumn::make('name_ar')->label(__('Name'))->searchable(['name_ar', 'name_en'])
                    ->weight(fn (Account $r) => $r->is_postable ? 'normal' : 'bold'),
                Tables\Columns\TextColumn::make('name_en')->label(__('English name'))->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('class')->label(__('Class'))->badge()->color('gray')->formatStateUsing(fn ($state) => __(Account::CLASSES[$state] ?? $state))->toggleable(),
                Tables\Columns\TextColumn::make('nature')->label(__('Normal balance'))->formatStateUsing(fn ($state) => $state === 'debit' ? __('Debit') : __('Credit'))->toggleable(),
                Tables\Columns\TextColumn::make('balance')->label(__('Balance'))->alignEnd()
                    ->state(function (Account $r) use ($balances) {
                        $all = $balances();
                        $ids = $r->is_postable ? [$r->id] : $r->descendantIds();
                        $sum = 0;
                        foreach ($ids as $id) {
                            $sum += \App\Support\Money::toCents((string) ($all[$id] ?? 0));
                        }

                        return $sum === 0 ? '' : \App\Support\Money::format($sum).' '.($sum > 0 ? __('Dr') : __('Cr'));
                    }),
                Tables\Columns\IconColumn::make('is_postable')->label(__('Postable'))->boolean()->toggleable(),
                Tables\Columns\TextColumn::make('flags')->label(__('Tags'))->badge()->state(fn (Account $r) => array_values(array_filter([
                    $r->is_cash ? __('Cash') : null, $r->is_bank ? __('Bank') : null, $r->is_non_deductible ? __('Non-deductible') : null,
                    $r->is_related_party ? __('Related party') : null, $r->system_key ? __('System') : null, $r->source === 'activity' ? __('Activity') : null,
                    ! $r->is_active ? __('Inactive') : null,
                ]))),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('class')->label(__('Class'))->options(fn () => collect(Account::CLASSES)->map(fn ($l) => __($l))->all()),
                Tables\Filters\SelectFilter::make('level')->label(__('Level'))->options([1 => 1, 2 => 2, 3 => 3, 4 => 4]),
                Tables\Filters\TernaryFilter::make('is_postable')->label(__('Postable')),
                Tables\Filters\TernaryFilter::make('is_active')->label(__('Active')),
                Tables\Filters\Filter::make('activity')->label(__('Activity-specific accounts'))->query(fn (Builder $q) => $q->where('source', 'activity')),
            ])
            ->groups([
                Tables\Grouping\Group::make('class')->label(__('Class'))->getTitleFromRecordUsing(fn ($record) => __(Account::CLASSES[$record->class])),
            ])
            ->actions([
                Tables\Actions\Action::make('ledger')->label(__('Ledger'))->icon('heroicon-o-document-magnifying-glass')->iconButton()
                    ->url(fn (Account $r) => Ledger::getUrl(['account' => $r->id])),
                Tables\Actions\Action::make('addChild')->label(__('Add sub-account'))->icon('heroicon-o-plus')->iconButton()
                    ->visible(fn (Account $r) => $r->level < 4 && auth()->user()->can(Perm::ACCOUNTS_MANAGE) && ! $r->lines()->exists())
                    ->url(fn (Account $r) => static::getUrl('create', ['parent' => $r->id])),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton()
                    ->action(fn (Account $record) => static::attempt(fn () => $record->delete(), __('Account deleted'))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}
