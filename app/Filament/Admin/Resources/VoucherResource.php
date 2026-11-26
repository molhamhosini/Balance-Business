<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Filament\Admin\Resources\VoucherResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\AuditLog;
use App\Models\TaxCode;
use App\Models\Voucher;
use App\Services\Accounting\VoucherService;
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

class VoucherResource extends Resource
{
    use ChecksPermissions, NotifiesAccountingErrors;

    protected static ?string $model = Voucher::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 2;
    protected static ?string $recordTitleAttribute = 'display_number';
    protected static ?string $viewPermission = Perm::VOUCHERS_VIEW;
    protected static ?string $createPermission = Perm::VOUCHERS_DRAFT;

    public static function getNavigationGroup(): ?string { return __('Accounting'); }
    public static function getModelLabel(): string { return __('Voucher'); }
    public static function getPluralModelLabel(): string { return __('Receipts & payments'); }

    public static function isEditable(Model $record): bool { return $record->status === 'draft'; }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();
        $allowed = auth()->user()->allowedBranchIds(Options::companyId());

        return $allowed ? $q->whereIn('branch_id', $allowed) : $q;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\ToggleButtons::make('type')->label(__('Voucher type'))->inline()->required()
                    ->options(fn () => collect(Voucher::TYPES)->map(fn ($l) => __($l))->all())
                    ->colors(['receipt' => 'success', 'payment' => 'danger'])
                    ->icons(['receipt' => 'heroicon-o-arrow-down-tray', 'payment' => 'heroicon-o-arrow-up-tray'])
                    ->default(fn () => request('type', 'receipt'))->live(),
                Forms\Components\Select::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches())->default(fn () => array_key_first(Options::branches()))->required(),
                Forms\Components\DatePicker::make('date')->label(__('Accounting date'))->default(now())->required()->native(false)->displayFormat('Y-m-d'),
                Forms\Components\Select::make('cash_account_id')->label(fn (Get $get) => $get('type') === 'payment' ? __('Paid from (cash / bank)') : __('Received into (cash / bank)'))
                    ->options(fn () => Options::cashAndBank())->searchable()->required(),
                Forms\Components\Select::make('counter_account_id')->label(fn (Get $get) => $get('type') === 'payment' ? __('Expense / supplier account') : __('Revenue / customer account'))
                    ->options(fn () => Options::postableAccounts())->searchable()->required(),
                Forms\Components\TextInput::make('party_name')->label(fn (Get $get) => $get('type') === 'payment' ? __('Paid to') : __('Received from')),
            ]),
            Forms\Components\Section::make(__('Amount'))->columns(4)->schema([
                Forms\Components\TextInput::make('amount')->label(__('Amount before VAT'))->numeric()->minValue(0.01)->required()->live(debounce: 400),
                Forms\Components\Select::make('tax_code_id')->label(__('Tax code'))->options(fn () => Options::taxCodes())->live()
                    ->default(fn () => TaxCode::where('company_id', Options::companyId())->where('code', 'OS')->value('id')),
                Forms\Components\Placeholder::make('vat_preview')->label(__('VAT'))->content(fn (Get $get) => Money::format(static::previewTax($get))),
                Forms\Components\Placeholder::make('total_preview')->label(__('Total'))->content(fn (Get $get) => Money::format(Money::toCents($get('amount') ?: 0) + static::previewTax($get))),
                Forms\Components\Select::make('payment_method')->label(__('Payment method'))->options(fn () => collect(Voucher::METHODS)->map(fn ($l) => __($l))->all())->default('cash')->live()->required(),
                Forms\Components\TextInput::make('cheque_number')->label(__('Cheque number'))->visible(fn (Get $get) => $get('payment_method') === 'cheque'),
                Forms\Components\DatePicker::make('cheque_due_date')->label(__('Cheque due date'))->visible(fn (Get $get) => $get('payment_method') === 'cheque'),
                Forms\Components\TextInput::make('description')->label(__('Description'))->columnSpanFull(),
            ]),
        ]);
    }

    private static function previewTax(Get $get): int
    {
        $code = $get('tax_code_id') ? TaxCode::find($get('tax_code_id')) : null;

        return $code && $code->type === 'standard' ? Money::percent(Money::toCents($get('amount') ?: 0), (string) $code->rate) : 0;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\ViewEntry::make('smart')->hiddenLabel()->view('filament.components.smart-buttons')->state(fn (Voucher $r) => array_values(array_filter([
                    $r->journalEntry ? ['icon' => 'heroicon-o-book-open', 'label' => __('Journal entry'), 'value' => $r->journalEntry->number, 'url' => JournalEntryResource::getUrl('view', ['record' => $r->journalEntry])] : null,
                    $r->journalEntry?->reversedBy ? ['icon' => 'heroicon-o-arrow-uturn-left', 'label' => __('Reversal entry'), 'value' => $r->journalEntry->reversedBy->number, 'url' => JournalEntryResource::getUrl('view', ['record' => $r->journalEntry->reversedBy])] : null,
                    ['icon' => 'heroicon-o-printer', 'label' => __('Print'), 'value' => '', 'url' => route('voucher.print', $r)],
                ]))),
                Infolists\Components\ViewEntry::make('statusbar')->hiddenLabel()->view('filament.components.statusbar')
                    ->state(fn (Voucher $r) => ['current' => $r->status, 'steps' => ['draft' => __('Draft'), 'posted' => __('Posted')] + ($r->status === 'cancelled' ? ['cancelled' => __('Cancelled')] : [])]),
            ]),
            Infolists\Components\Section::make(fn (Voucher $r) => __(Voucher::TYPES[$r->type]).' '.$r->display_number)->columns(4)->schema([
                Infolists\Components\TextEntry::make('date')->label(__('Accounting date'))->date('Y-m-d'),
                Infolists\Components\TextEntry::make('branch.name')->label(__('Branch')),
                Infolists\Components\TextEntry::make('party_name')->label(__('Party'))->placeholder('—'),
                Infolists\Components\TextEntry::make('payment_method')->label(__('Payment method'))->formatStateUsing(fn ($state) => __(Voucher::METHODS[$state] ?? $state)),
                Infolists\Components\TextEntry::make('cashAccount.label')->label(__('Cash / bank')),
                Infolists\Components\TextEntry::make('counterAccount.label')->label(__('Counter account'))->columnSpan(2),
                Infolists\Components\TextEntry::make('taxCode.code')->label(__('Tax code'))->placeholder('—'),
                Infolists\Components\TextEntry::make('amount')->label(__('Amount before VAT'))->numeric(2, locale: 'en'),
                Infolists\Components\TextEntry::make('tax_amount')->label(__('VAT'))->numeric(2, locale: 'en'),
                Infolists\Components\TextEntry::make('total')->label(__('Total'))->numeric(2, locale: 'en')->weight('bold')->size('lg'),
                Infolists\Components\TextEntry::make('description')->label(__('Description'))->placeholder('—'),
                Infolists\Components\TextEntry::make('creator.name')->label(__('Entered by')),
                Infolists\Components\TextEntry::make('poster.name')->label(__('Posted by'))->placeholder('—'),
            ]),
            Infolists\Components\Section::make(__('Journal items'))->visible(fn (Voucher $r) => $r->journalEntry !== null)->schema([
                Infolists\Components\ViewEntry::make('lines_table')->hiddenLabel()->view('filament.components.entry-lines')->state(fn (Voucher $r) => $r->journalEntry?->lines()->with(['account', 'taxCode'])->get()),
            ]),
            Infolists\Components\Section::make(__('Activity'))->collapsible()->schema([
                Infolists\Components\ViewEntry::make('chatter')->hiddenLabel()->view('filament.components.chatter')
                    ->state(fn (Voucher $r) => AuditLog::with('user')->where('auditable_type', $r->getMorphClass())->where('auditable_id', $r->id)->latest('id')->get()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('display_number')->label(__('Number'))->searchable(['number'])->weight('bold'),
                Tables\Columns\TextColumn::make('type')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => __(Voucher::TYPES[$state]))
                    ->color(fn ($state) => $state === 'receipt' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('date')->label(__('Date'))->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('party_name')->label(__('Party'))->searchable(),
                Tables\Columns\TextColumn::make('counterAccount.name_ar')->label(__('Account'))->toggleable(),
                Tables\Columns\TextColumn::make('cashAccount.name_ar')->label(__('Cash / bank'))->toggleable(),
                Tables\Columns\TextColumn::make('total')->label(__('Total'))->numeric(2, locale: 'en')->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __(Voucher::STATUSES[$state]))
                    ->color(fn ($state) => match ($state) { 'posted' => 'success', 'cancelled' => 'danger', default => 'gray' }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label(__('Type'))->options(fn () => collect(Voucher::TYPES)->map(fn ($l) => __($l))->all()),
                Tables\Filters\SelectFilter::make('status')->label(__('Status'))->options(fn () => collect(Voucher::STATUSES)->map(fn ($l) => __($l))->all()),
                Tables\Filters\SelectFilter::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches()),
                Tables\Filters\SelectFilter::make('payment_method')->label(__('Payment method'))->options(fn () => collect(Voucher::METHODS)->map(fn ($l) => __($l))->all()),
            ])
            ->groups([
                Tables\Grouping\Group::make('type')->label(__('Type'))->getTitleFromRecordUsing(fn ($record) => __(Voucher::TYPES[$record->type])),
                Tables\Grouping\Group::make('status')->label(__('Status'))->getTitleFromRecordUsing(fn ($record) => __(Voucher::STATUSES[$record->status])),
                Tables\Grouping\Group::make('date')->label(__('Month'))->date()->getTitleFromRecordUsing(fn ($record) => $record->date->format('Y-m')),
                Tables\Grouping\Group::make('cashAccount.name_ar')->label(__('Cash / bank')),
            ])
            ->actions([Tables\Actions\ViewAction::make()->iconButton(), Tables\Actions\EditAction::make()->iconButton()])
            ->recordUrl(fn (Voucher $r) => static::getUrl('view', ['record' => $r]));
    }

    public static function postAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('post')->label(__('Post'))->icon('heroicon-o-check-circle')->color('primary')
            ->visible(fn (Voucher $record) => $record->status === 'draft' && auth()->user()->can(Perm::VOUCHERS_POST))
            ->requiresConfirmation()->modalDescription(__('Posting creates the journal entry automatically. A posted voucher can only be cancelled.'))
            ->action(fn (Voucher $record, $livewire) => static::attempt(fn () => app(VoucherService::class)->post($record, auth()->user()), __('Voucher posted')) && $livewire->refreshRecord());
    }

    public static function cancelAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('cancel')->label(__('Cancel voucher'))->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (Voucher $record) => $record->status === 'posted' && auth()->user()->can(Perm::VOUCHERS_CANCEL))
            ->form([
                Forms\Components\DatePicker::make('date')->label(__('Reversal date'))->default(fn (Voucher $record) => $record->date)->required(),
                Forms\Components\TextInput::make('reason')->label(__('Reason'))->required(),
            ])
            ->action(fn (Voucher $record, array $data, $livewire) => static::attempt(fn () => app(VoucherService::class)->cancel($record, auth()->user(), $data['reason'], $data['date']), __('Voucher cancelled')) && $livewire->refreshRecord());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVouchers::route('/'),
            'create' => Pages\CreateVoucher::route('/create'),
            'view' => Pages\ViewVoucher::route('/{record}'),
            'edit' => Pages\EditVoucher::route('/{record}/edit'),
        ];
    }
}
