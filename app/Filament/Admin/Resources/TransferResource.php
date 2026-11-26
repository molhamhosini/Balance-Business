<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Filament\Admin\Resources\TransferResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\AuditLog;
use App\Models\Transfer;
use App\Services\Accounting\BankingService;
use App\Support\Perm;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Transfer between cash boxes and banks (Dr receiving account / Cr sending account). */
class TransferResource extends Resource
{
    use ChecksPermissions, NotifiesAccountingErrors;

    protected static ?string $model = Transfer::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?int $navigationSort = 3;
    protected static ?string $viewPermission = Perm::VOUCHERS_VIEW;
    protected static ?string $createPermission = Perm::VOUCHERS_DRAFT;

    public static function getNavigationGroup(): ?string { return __('Cash & banks'); }
    public static function getModelLabel(): string { return __('Cash transfer'); }
    public static function getPluralModelLabel(): string { return __('Cash transfers'); }

    public static function isEditable(Model $record): bool { return $record->status === 'draft'; }

    public static function form(Form $form): Form
    {
        return $form->schema([Forms\Components\Section::make()->columns(3)->schema([
            Forms\Components\Select::make('from_account_id')->label(__('From (cash / bank)'))->options(fn () => Options::cashAndBank())->searchable()->required(),
            Forms\Components\Select::make('to_account_id')->label(__('To (cash / bank)'))->options(fn () => Options::cashAndBank())->searchable()->required()->different('from_account_id'),
            Forms\Components\TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0.01)->required(),
            Forms\Components\DatePicker::make('date')->label(__('Accounting date'))->default(now())->required()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\Select::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches())->default(fn () => array_key_first(Options::branches()))->required(),
            Forms\Components\TextInput::make('reference')->label(__('Reference')),
            Forms\Components\TextInput::make('description')->label(__('Description'))->columnSpanFull(),
        ])]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\ViewEntry::make('smart')->hiddenLabel()->view('filament.components.smart-buttons')->state(fn (Transfer $record) => array_values(array_filter([
                    $record->journalEntry ? ['icon' => 'heroicon-o-book-open', 'label' => __('Journal entry'), 'value' => $record->journalEntry->number, 'url' => JournalEntryResource::getUrl('view', ['record' => $record->journalEntry])] : null,
                ]))),
                Infolists\Components\ViewEntry::make('statusbar')->hiddenLabel()->view('filament.components.statusbar')
                    ->state(fn (Transfer $record) => ['current' => $record->status, 'steps' => ['draft' => __('Draft'), 'posted' => __('Posted')] + ($record->status === 'cancelled' ? ['cancelled' => __('Cancelled')] : [])]),
            ]),
            Infolists\Components\Section::make(fn (Transfer $record) => __('Cash transfer').' '.$record->display_number)->columns(3)->schema([
                Infolists\Components\TextEntry::make('fromAccount.label')->label(__('From (cash / bank)')),
                Infolists\Components\TextEntry::make('toAccount.label')->label(__('To (cash / bank)')),
                Infolists\Components\TextEntry::make('amount')->label(__('Amount'))->numeric(2, locale: 'en')->weight('bold')->size('lg'),
                Infolists\Components\TextEntry::make('date')->label(__('Accounting date'))->date('Y-m-d'),
                Infolists\Components\TextEntry::make('branch.name')->label(__('Branch')),
                Infolists\Components\TextEntry::make('reference')->label(__('Reference'))->placeholder('—'),
                Infolists\Components\TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make(__('Activity'))->collapsible()->schema([
                Infolists\Components\ViewEntry::make('chatter')->hiddenLabel()->view('filament.components.chatter')
                    ->state(fn (Transfer $record) => AuditLog::with('user')->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->latest('id')->get()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('date', 'desc')->columns([
            Tables\Columns\TextColumn::make('display_number')->label(__('Number'))->searchable(['number'])->weight('bold'),
            Tables\Columns\TextColumn::make('date')->label(__('Date'))->date('Y-m-d')->sortable(),
            Tables\Columns\TextColumn::make('fromAccount.name_ar')->label(__('From (cash / bank)')),
            Tables\Columns\TextColumn::make('toAccount.name_ar')->label(__('To (cash / bank)')),
            Tables\Columns\TextColumn::make('amount')->label(__('Amount'))->numeric(2, locale: 'en')->alignEnd(),
            Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __(Transfer::STATUSES[$state]))
                ->color(fn ($state) => match ($state) { 'posted' => 'success', 'cancelled' => 'danger', default => 'gray' }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->label(__('Status'))->options(fn () => collect(Transfer::STATUSES)->map(fn ($l) => __($l))->all()),
        ])->actions([Tables\Actions\ViewAction::make()->iconButton(), Tables\Actions\EditAction::make()->iconButton()])
            ->recordUrl(fn (Transfer $record) => static::getUrl('view', ['record' => $record]));
    }

    public static function headerActions(): array
    {
        return [
            Action::make('post')->label(__('Post'))->icon('heroicon-o-check-circle')->requiresConfirmation()
                ->visible(fn (Transfer $record) => $record->status === 'draft' && auth()->user()->can(Perm::VOUCHERS_POST))
                ->action(fn (Transfer $record, $livewire) => static::attempt(fn () => app(BankingService::class)->postTransfer($record, auth()->user()), __('Posted')) && $livewire->refreshRecord()),
            Action::make('cancel')->label(__('Cancel'))->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Transfer $record) => $record->status === 'posted' && auth()->user()->can(Perm::VOUCHERS_CANCEL))
                ->form([Forms\Components\TextInput::make('reason')->label(__('Reason'))->required()])
                ->action(fn (Transfer $record, array $data, $livewire) => static::attempt(fn () => app(BankingService::class)->cancelTransfer($record, auth()->user(), $data['reason']), __('Cancelled')) && $livewire->refreshRecord()),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransfers::route('/'),
            'create' => Pages\CreateTransfer::route('/create'),
            'view' => Pages\ViewTransfer::route('/{record}'),
            'edit' => Pages\EditTransfer::route('/{record}/edit'),
        ];
    }
}
