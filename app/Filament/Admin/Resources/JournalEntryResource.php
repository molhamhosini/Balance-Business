<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Filament\Admin\Resources\JournalEntryResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\Voucher;
use App\Services\Accounting\PostingService;
use App\Support\Money;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class JournalEntryResource extends Resource
{
    use ChecksPermissions, NotifiesAccountingErrors;

    protected static ?string $model = JournalEntry::class;
    protected static ?string $navigationIcon = 'heroicon-o-book-open';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'display_number';
    protected static ?string $viewPermission = Perm::ENTRIES_VIEW;
    protected static ?string $createPermission = Perm::ENTRIES_DRAFT;

    public static function getNavigationGroup(): ?string { return __('Accounting'); }
    public static function getModelLabel(): string { return __('Journal entry'); }
    public static function getPluralModelLabel(): string { return __('Journal entries'); }

    public static function isEditable(Model $record): bool
    {
        return $record->isDraft() && ! $record->isAutomatic();
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();
        $allowed = auth()->user()->allowedBranchIds(Options::companyId());

        return $allowed ? $q->whereIn('branch_id', $allowed) : $q;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(4)->schema([
                Forms\Components\Select::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches())
                    ->default(fn () => array_key_first(Options::branches()))->required(),
                Forms\Components\DatePicker::make('date')->label(__('Accounting date'))->default(now())->required()->native(false)->displayFormat('Y-m-d'),
                Forms\Components\TextInput::make('reference')->label(__('Reference'))->maxLength(255),
                Forms\Components\Select::make('type')->label(__('Type'))->options(['manual' => __('Manual entry'), 'opening' => __('Opening entry')])->default('manual')->required(),
                Forms\Components\TextInput::make('description')->label(__('Description'))->columnSpanFull()->maxLength(255),
            ]),
            Forms\Components\Section::make(__('Lines'))->schema([
                Forms\Components\Repeater::make('lines')->hiddenLabel()->live(debounce: 400)
                    ->schema([
                        Forms\Components\Select::make('account_id')->label(__('Account'))->options(fn () => Options::postableAccounts())->searchable()->required()->columnSpan(3),
                        Forms\Components\TextInput::make('description')->label(__('Label'))->columnSpan(2),
                        Forms\Components\Select::make('cost_center_id')->label(__('Cost center'))->options(fn () => Options::costCenters())->columnSpan(2)->placeholder('—'),
                        Forms\Components\TextInput::make('debit')->label(__('Debit'))->numeric()->minValue(0)->default(0)->columnSpan(2),
                        Forms\Components\TextInput::make('credit')->label(__('Credit'))->numeric()->minValue(0)->default(0)->columnSpan(2),
                        Forms\Components\Select::make('tax_code_id')->label(__('Tax'))->options(fn () => Options::taxCodes())->columnSpan(1)->placeholder('—'),
                    ])
                    ->columns(12)->minItems(2)->defaultItems(2)->reorderable()->cloneable()
                    ->addActionLabel(__('Add a line')),
                Forms\Components\Placeholder::make('totals')->hiddenLabel()->content(function (Get $get) {
                    $d = $c = 0;
                    foreach ($get('lines') ?? [] as $l) {
                        $d += Money::toCents($l['debit'] ?? 0);
                        $c += Money::toCents($l['credit'] ?? 0);
                    }
                    $diff = $d - $c;
                    $state = $diff === 0 && $d > 0 ? '<span class="bb-ok">'.__('Balanced').'</span>' : '<span class="bb-bad">'.__('Difference').': '.Money::format($diff).'</span>';

                    return new \Illuminate\Support\HtmlString(__('Total debit').': <b>'.Money::format($d).'</b> &nbsp; '.__('Total credit').': <b>'.Money::format($c).'</b> &nbsp; '.$state);
                }),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\ViewEntry::make('smart')->hiddenLabel()->view('filament.components.smart-buttons')
                    ->state(fn (JournalEntry $r) => static::smartButtons($r)),
                Infolists\Components\ViewEntry::make('statusbar')->hiddenLabel()->view('filament.components.statusbar')
                    ->state(fn (JournalEntry $r) => ['current' => $r->status, 'steps' => ['draft' => __('Draft'), 'posted' => __('Posted')] + ($r->status === 'reversed' ? ['reversed' => __('Reversed')] : [])]),
            ]),
            Infolists\Components\Section::make(fn (JournalEntry $r) => $r->display_number)->columns(4)->schema([
                Infolists\Components\TextEntry::make('date')->label(__('Accounting date'))->date('Y-m-d'),
                Infolists\Components\TextEntry::make('branch.name')->label(__('Branch')),
                Infolists\Components\TextEntry::make('type')->label(__('Type'))->formatStateUsing(fn ($state) => __(JournalEntry::TYPES[$state] ?? $state)),
                Infolists\Components\TextEntry::make('reference')->label(__('Reference'))->placeholder('—'),
                Infolists\Components\TextEntry::make('description')->label(__('Description'))->columnSpan(2)->placeholder('—'),
                Infolists\Components\TextEntry::make('creator.name')->label(__('Entered by')),
                Infolists\Components\TextEntry::make('created_at')->label(__('Entry date'))->dateTime('Y-m-d H:i'),
                Infolists\Components\TextEntry::make('poster.name')->label(__('Posted by'))->placeholder('—'),
                Infolists\Components\TextEntry::make('posted_at')->label(__('Posted at'))->dateTime('Y-m-d H:i')->placeholder('—'),
            ]),
            Infolists\Components\Section::make(__('Lines'))->schema([
                Infolists\Components\ViewEntry::make('lines_table')->hiddenLabel()->view('filament.components.entry-lines')->state(fn (JournalEntry $r) => $r->lines()->with(['account', 'taxCode'])->get()),
            ]),
            Infolists\Components\Section::make(__('Activity'))->collapsible()->schema([
                Infolists\Components\ViewEntry::make('chatter')->hiddenLabel()->view('filament.components.chatter')
                    ->state(fn (JournalEntry $r) => AuditLog::with('user')->where('auditable_type', $r->getMorphClass())->where('auditable_id', $r->id)->latest('id')->get()),
            ]),
        ]);
    }

    public static function smartButtons(JournalEntry $r): array
    {
        $b = [];
        if ($r->source_type === (new Voucher)->getMorphClass() && $r->source) {
            $b[] = ['icon' => 'heroicon-o-banknotes', 'label' => __(Voucher::TYPES[$r->source->type]), 'value' => $r->source->display_number, 'url' => VoucherResource::getUrl('view', ['record' => $r->source])];
        }
        if ($r->reversedBy) {
            $b[] = ['icon' => 'heroicon-o-arrow-uturn-left', 'label' => __('Reversal entry'), 'value' => $r->reversedBy->number, 'url' => static::getUrl('view', ['record' => $r->reversedBy])];
        }
        if ($r->reverses) {
            $b[] = ['icon' => 'heroicon-o-document-text', 'label' => __('Original entry'), 'value' => $r->reverses->number, 'url' => static::getUrl('view', ['record' => $r->reverses])];
        }
        $b[] = ['icon' => 'heroicon-o-list-bullet', 'label' => __('Lines'), 'value' => $r->lines()->count(), 'url' => '#'];

        return $b;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('display_number')->label(__('Number'))->searchable(['number'])->weight('bold'),
                Tables\Columns\TextColumn::make('date')->label(__('Date'))->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('branch.name_ar')->formatStateUsing(\App\Support\Localized::name('branch'))->label(__('Branch'))->toggleable(),
                Tables\Columns\TextColumn::make('description')->label(__('Description'))->limit(45)->searchable()->wrap(),
                Tables\Columns\TextColumn::make('type')->label(__('Type'))->badge()->color('gray')->formatStateUsing(fn ($state) => __(JournalEntry::TYPES[$state] ?? $state)),
                Tables\Columns\TextColumn::make('total')->label(__('Amount'))->numeric(2, locale: 'en')->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->numeric(2, locale: 'en')->label('')),
                Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn ($state) => __(JournalEntry::STATUSES[$state] ?? $state))
                    ->color(fn ($state) => match ($state) { 'posted' => 'success', 'reversed' => 'danger', default => 'gray' }),
                Tables\Columns\TextColumn::make('creator.name')->label(__('Entered by'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('Status'))->options(fn () => collect(JournalEntry::STATUSES)->map(fn ($l) => __($l))->all()),
                Tables\Filters\SelectFilter::make('type')->label(__('Type'))->options(fn () => collect(JournalEntry::TYPES)->map(fn ($l) => __($l))->all()),
                Tables\Filters\SelectFilter::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches()),
                Tables\Filters\Filter::make('date')->form([
                    Forms\Components\DatePicker::make('from')->label(__('From')),
                    Forms\Components\DatePicker::make('until')->label(__('To')),
                ])->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))),
            ])
            ->groups([
                Tables\Grouping\Group::make('status')->label(__('Status'))->getTitleFromRecordUsing(fn ($record) => __(JournalEntry::STATUSES[$record->status])),
                Tables\Grouping\Group::make('type')->label(__('Type'))->getTitleFromRecordUsing(fn ($record) => __(JournalEntry::TYPES[$record->type])),
                Tables\Grouping\Group::make('date')->label(__('Month'))->date()->getTitleFromRecordUsing(fn ($record) => $record->date->format('Y-m')),
                Tables\Grouping\Group::make('branch.name_ar')->label(__('Branch')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\EditAction::make()->iconButton(),
            ])
            ->recordUrl(fn (JournalEntry $r) => static::getUrl('view', ['record' => $r]));
    }

    public static function postAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('post')->label(__('Post'))->icon('heroicon-o-check-circle')->color('primary')
            ->visible(fn (JournalEntry $record) => $record->isDraft() && ! $record->isAutomatic() && auth()->user()->can(Perm::ENTRIES_POST))
            ->requiresConfirmation()->modalDescription(__('After posting the entry cannot be edited or deleted; only reversed.'))
            ->action(fn (JournalEntry $record, $livewire) => static::attempt(fn () => app(PostingService::class)->post($record, auth()->user()), __('Entry posted')) && $livewire->refreshRecord());
    }

    public static function reverseAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('reverse')->label(__('Reverse'))->icon('heroicon-o-arrow-uturn-left')->color('danger')
            ->visible(fn (JournalEntry $record) => $record->status === 'posted' && ! $record->reverses_id && ! $record->isAutomatic() && auth()->user()->can(Perm::ENTRIES_REVERSE))
            ->form([
                Forms\Components\DatePicker::make('date')->label(__('Reversal date'))->default(fn (JournalEntry $record) => $record->date)->required(),
                Forms\Components\TextInput::make('reason')->label(__('Reason'))->required(),
            ])
            ->action(function (JournalEntry $record, array $data, $livewire) {
                $rev = null;
                if (static::attempt(function () use ($record, $data, &$rev) { $rev = app(PostingService::class)->reverse($record, auth()->user(), $data['reason'], $data['date']); }, __('Entry reversed'))) {
                    $livewire->redirect(static::getUrl('view', ['record' => $rev]), navigate: true);
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalEntries::route('/'),
            'create' => Pages\CreateJournalEntry::route('/create'),
            'view' => Pages\ViewJournalEntry::route('/{record}'),
            'edit' => Pages\EditJournalEntry::route('/{record}/edit'),
        ];
    }
}
