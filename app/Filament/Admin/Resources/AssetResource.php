<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Filament\Admin\Resources\AssetResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AuditLog;
use App\Services\Accounting\AssetService;
use App\Support\Money;
use App\Support\Perm;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Fixed asset card, straight-line monthly depreciation, sale / disposal with gain or loss (requirements §6). */
class AssetResource extends Resource
{
    use ChecksPermissions, NotifiesAccountingErrors;

    protected static ?string $model = Asset::class;
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 1;
    protected static ?string $viewPermission = Perm::ASSETS_MANAGE;
    protected static ?string $createPermission = Perm::ASSETS_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Fixed assets'); }
    public static function getModelLabel(): string { return __('Fixed asset'); }
    public static function getPluralModelLabel(): string { return __('Fixed assets'); }

    public static function canDelete(Model $record): bool { return false; }

    public static function form(Form $form): Form
    {
        $locked = fn (?Asset $record) => $record && ($record->depreciations()->exists() || $record->status === 'disposed');

        return $form->schema([
            Forms\Components\Section::make()->columns(4)->schema([
                Forms\Components\TextInput::make('code')->label(__('Code'))->placeholder(__('Automatic'))->maxLength(30)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('company_id', Options::companyId())),
                Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required()->columnSpan(2),
                Forms\Components\TextInput::make('name_en')->label(__('English name')),
                Forms\Components\Select::make('asset_category_id')->label(__('Category'))->required()->live()->disabled($locked)->dehydrated()
                    ->options(fn () => AssetCategory::where('company_id', Options::companyId())->orderBy('id')->get()->mapWithKeys(fn ($c) => [$c->id => $c->name])->all())
                    ->afterStateUpdated(fn ($state, Set $set) => $state && $set('useful_life_months', AssetCategory::find($state)?->useful_life_months)),
                Forms\Components\Select::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches())->default(fn () => array_key_first(Options::branches()))->required(),
                Forms\Components\Select::make('cost_center_id')->label(__('Cost center'))->options(fn () => Options::costCenters())->searchable(),
                Forms\Components\DatePicker::make('purchase_date')->label(__('Purchase date'))->required()->native(false)->displayFormat('Y-m-d')->disabled($locked)->dehydrated(),
            ]),
            Forms\Components\Section::make(__('Depreciation'))->columns(4)->schema([
                Forms\Components\TextInput::make('cost')->label(__('Cost'))->numeric()->minValue(0.01)->required()->live(debounce: 500)->disabled($locked)->dehydrated(),
                Forms\Components\TextInput::make('salvage_value')->label(__('Salvage value'))->numeric()->minValue(0)->default(0)->live(debounce: 500)->disabled($locked)->dehydrated(),
                Forms\Components\TextInput::make('useful_life_months')->label(__('Useful life (months)'))->numeric()->integer()->minValue(1)->required()->live(debounce: 500)->disabled($locked)->dehydrated(),
                Forms\Components\Placeholder::make('monthly')->label(__('Monthly depreciation'))
                    ->content(fn (Get $get) => $get('useful_life_months') ? Money::format(intdiv(max(0, Money::toCents($get('cost') ?: 0) - Money::toCents($get('salvage_value') ?: 0)), max(1, (int) $get('useful_life_months')))) : '—'),
                Forms\Components\DatePicker::make('depreciation_start')->label(__('Depreciation starts'))->native(false)->displayFormat('Y-m-d')->disabled($locked)->dehydrated()
                    ->helperText(__('Default: first day of the purchase month.')),
                Forms\Components\TextInput::make('opening_accumulated')->label(__('Accumulated depreciation before go-live'))->numeric()->minValue(0)->default(0)->disabled($locked)->dehydrated(),
            ]),
            Forms\Components\Section::make(__('Details'))->columns(2)->collapsible()->schema([
                Forms\Components\TextInput::make('location')->label(__('Location')),
                Forms\Components\TextInput::make('serial')->label(__('Serial / plate number')),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\ViewEntry::make('smart')->hiddenLabel()->view('filament.components.smart-buttons')->state(fn (Asset $record) => array_values(array_filter([
                    ['icon' => 'heroicon-o-banknotes', 'label' => __('Net book value'), 'value' => Money::format($record->bookValueCents()), 'url' => \App\Filament\Admin\Pages\AssetRegister::getUrl()],
                    ['icon' => 'heroicon-o-arrow-trending-down', 'label' => __('Accumulated depreciation'), 'value' => Money::format($record->accumulatedCents()), 'url' => \App\Filament\Admin\Pages\Ledger::getUrl(['account' => $record->category->accumulated_account_id])],
                    $record->disposalEntry ? ['icon' => 'heroicon-o-book-open', 'label' => __('Disposal entry'), 'value' => $record->disposalEntry->number, 'url' => JournalEntryResource::getUrl('view', ['record' => $record->disposalEntry])] : null,
                ]))),
                Infolists\Components\ViewEntry::make('statusbar')->hiddenLabel()->view('filament.components.statusbar')
                    ->state(fn (Asset $record) => ['current' => $record->status, 'steps' => collect(Asset::STATUSES)->map(fn ($l) => __($l))->all()]),
            ]),
            Infolists\Components\Section::make(fn (Asset $record) => $record->label)->columns(4)->schema([
                Infolists\Components\TextEntry::make('category.name')->label(__('Category')),
                Infolists\Components\TextEntry::make('branch.name')->label(__('Branch')),
                Infolists\Components\TextEntry::make('costCenter.label')->label(__('Cost center'))->placeholder('—'),
                Infolists\Components\TextEntry::make('purchase_date')->label(__('Purchase date'))->date('Y-m-d'),
                Infolists\Components\TextEntry::make('cost')->label(__('Cost'))->numeric(2, locale: 'en')->weight('bold'),
                Infolists\Components\TextEntry::make('salvage_value')->label(__('Salvage value'))->numeric(2, locale: 'en'),
                Infolists\Components\TextEntry::make('useful_life_months')->label(__('Useful life (months)')),
                Infolists\Components\TextEntry::make('monthly')->label(__('Monthly depreciation'))->state(fn (Asset $record) => Money::format($record->monthlyCents())),
                Infolists\Components\TextEntry::make('depreciation_start')->label(__('Depreciation starts'))->date('Y-m-d'),
                Infolists\Components\TextEntry::make('location')->label(__('Location'))->placeholder('—'),
                Infolists\Components\TextEntry::make('serial')->label(__('Serial / plate number'))->placeholder('—'),
                Infolists\Components\TextEntry::make('disposal_date')->label(__('Disposal date'))->date('Y-m-d')->visible(fn (Asset $record) => $record->status === 'disposed'),
            ]),
            Infolists\Components\Section::make(__('Depreciation schedule'))->collapsible()->schema([
                Infolists\Components\RepeatableEntry::make('depreciations')->hiddenLabel()->columns(3)->contained(false)->schema([
                    Infolists\Components\TextEntry::make('period_end')->label(__('Month'))->date('Y-m'),
                    Infolists\Components\TextEntry::make('amount')->label(__('Amount'))->numeric(2, locale: 'en'),
                    Infolists\Components\TextEntry::make('run.journalEntry.number')->label(__('Entry'))->placeholder('—')
                        ->url(fn ($record) => $record->run?->journalEntry ? JournalEntryResource::getUrl('view', ['record' => $record->run->journalEntry]) : null)->color('primary'),
                ])->placeholder(__('No depreciation posted yet.')),
            ]),
            Infolists\Components\Section::make(__('Activity'))->collapsible()->schema([
                Infolists\Components\ViewEntry::make('chatter')->hiddenLabel()->view('filament.components.chatter')
                    ->state(fn (Asset $record) => AuditLog::with('user')->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->latest('id')->get()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')->columns([
            Tables\Columns\TextColumn::make('code')->label(__('Code'))->weight('bold')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name_ar')->label(__('Name'))->searchable(['name_ar', 'name_en', 'serial']),
            Tables\Columns\TextColumn::make('category.name_ar')->label(__('Category')),
            Tables\Columns\TextColumn::make('purchase_date')->label(__('Purchase date'))->date('Y-m-d')->sortable(),
            Tables\Columns\TextColumn::make('cost')->label(__('Cost'))->numeric(2, locale: 'en')->alignEnd(),
            Tables\Columns\TextColumn::make('nbv')->label(__('Net book value'))->state(fn (Asset $record) => Money::format($record->bookValueCents()))->alignEnd(),
            Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __(Asset::STATUSES[$state]))
                ->color(fn ($state) => match ($state) { 'running' => 'success', 'disposed' => 'danger', default => 'gray' }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->label(__('Status'))->options(fn () => collect(Asset::STATUSES)->map(fn ($l) => __($l))->all()),
            Tables\Filters\SelectFilter::make('asset_category_id')->label(__('Category'))->relationship('category', 'name_ar'),
        ])->actions([Tables\Actions\ViewAction::make()->iconButton(), Tables\Actions\EditAction::make()->iconButton()])
            ->recordUrl(fn (Asset $record) => static::getUrl('view', ['record' => $record]));
    }

    /** "Run depreciation" for a month: one entry for all running assets. */
    public static function runDepreciationAction(): Action
    {
        return Action::make('depreciate')->label(__('Run monthly depreciation'))->icon('heroicon-o-play')
            ->visible(fn () => auth()->user()->can(Perm::ASSETS_DEPRECIATE))
            ->form([
                Forms\Components\DatePicker::make('month')->label(__('Month'))->required()->native(false)->displayFormat('Y-m')
                    ->default(fn () => now()->subMonthNoOverflow()->endOfMonth())
                    ->helperText(__('Posts one entry dated the last day of the month for every running asset.')),
            ])
            ->action(function (array $data) {
                static::attempt(function () use ($data) {
                    $run = app(AssetService::class)->run(\Filament\Facades\Filament::getTenant(), $data['month'], auth()->user());
                    \Filament\Notifications\Notification::make()->success()->title(__('Depreciation posted'))
                        ->body($run->journalEntry->number.' · '.Money::format($run->total))
                        ->actions([\Filament\Notifications\Actions\Action::make('open')->label(__('Open entry'))->url(JournalEntryResource::getUrl('view', ['record' => $run->journalEntry]))])->send();
                });
            });
    }

    public static function disposeAction(): Action
    {
        return Action::make('dispose')->label(__('Sell / dispose'))->icon('heroicon-o-archive-box-x-mark')->color('danger')
            ->visible(fn (Asset $record) => $record->status !== 'disposed' && auth()->user()->can(Perm::ASSETS_DEPRECIATE))
            ->form([
                Forms\Components\DatePicker::make('date')->label(__('Date'))->default(now())->required()->native(false)->displayFormat('Y-m-d'),
                Forms\Components\TextInput::make('proceeds')->label(__('Sale proceeds (0 if scrapped)'))->numeric()->minValue(0)->default(0)->required()->live(debounce: 500),
                Forms\Components\Select::make('account')->label(__('Proceeds received in'))->searchable()
                    ->options(fn () => Options::postableAccounts(fn ($q) => $q->where(fn ($w) => $w->where('is_cash', true)->orWhere('is_bank', true)->orWhere('code', 'like', '113%'))))
                    ->required(fn (Get $get) => (float) $get('proceeds') > 0),
                Forms\Components\Placeholder::make('result')->label(__('Gain / loss'))->content(fn (Get $get, Asset $record) => Money::format(Money::toCents($get('proceeds') ?: 0) - $record->bookValueCents())),
                Forms\Components\TextInput::make('reason')->label(__('Reason'))->required(),
            ])
            ->action(fn (Asset $record, array $data, $livewire) => static::attempt(fn () => app(AssetService::class)->dispose($record, $data['date'], (string) $data['proceeds'], $data['account'] ? (int) $data['account'] : null, auth()->user(), $data['reason']), __('Saved')) && $livewire->refreshRecord());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'view' => Pages\ViewAsset::route('/{record}'),
            'edit' => Pages\EditAsset::route('/{record}/edit'),
        ];
    }
}
