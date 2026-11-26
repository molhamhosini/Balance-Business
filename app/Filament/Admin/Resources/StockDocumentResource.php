<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Filament\Admin\Resources\StockDocumentResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\StockDocument;
use App\Services\Accounting\InventoryService;
use App\Support\Perm;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Stock documents: opening stock, issue, warehouse transfer, physical count, landed cost. */
class StockDocumentResource extends Resource
{
    use ChecksPermissions, NotifiesAccountingErrors;

    protected static ?string $model = StockDocument::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?int $navigationSort = 2;
    protected static ?string $viewPermission = Perm::INVENTORY_VIEW;
    protected static ?string $createPermission = Perm::INVENTORY_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Inventory'); }
    public static function getModelLabel(): string { return __('Stock document'); }
    public static function getPluralModelLabel(): string { return __('Stock documents'); }

    public static function isEditable(Model $record): bool { return $record->status === 'draft'; }

    public static function form(Form $form): Form
    {
        $is = fn (string ...$types) => fn (Get $get) => in_array($get('type'), $types, true);
        $isLine = fn (string ...$types) => fn (Get $get) => in_array($get('../../type'), $types, true);

        return $form->schema([
            Forms\Components\Section::make()->columns(4)->schema([
                Forms\Components\Select::make('type')->label(__('Document type'))->required()->live()
                    ->options(fn () => collect(StockDocument::TYPES)->map(fn ($l) => __($l))->all())->default(fn () => request('type', 'issue'))
                    ->disabled(fn (?StockDocument $record) => (bool) $record?->exists)->dehydrated(),
                Forms\Components\DatePicker::make('date')->label(__('Accounting date'))->default(now())->required()->native(false)->displayFormat('Y-m-d'),
                Forms\Components\Select::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches())->default(fn () => array_key_first(Options::branches()))->required(),
                Forms\Components\Select::make('warehouse_id')->label(fn (Get $get) => $get('type') === 'transfer' ? __('From warehouse') : __('Warehouse'))
                    ->options(fn () => Options::warehouses())->default(fn () => array_key_first(Options::warehouses()))->required(fn (Get $get) => $get('type') !== 'landed_cost'),
                Forms\Components\Select::make('to_warehouse_id')->label(__('To warehouse'))->options(fn () => Options::warehouses())->visible($is('transfer'))->required($is('transfer'))->different('warehouse_id'),
                Forms\Components\Select::make('counter_account_id')->searchable()->visible($is('opening', 'issue', 'count', 'landed_cost'))->required($is('opening', 'issue', 'landed_cost'))
                    ->label(fn (Get $get) => match ($get('type')) {
                        'opening' => __('Opening balance account'), 'issue' => __('Expense / cost account'),
                        'count' => __('Difference account (default: inventory variance)'), default => __('Account to credit (payable / clearing)'),
                    })
                    ->options(fn (Get $get) => Options::postableAccounts(fn ($q) => match ($get('type')) {
                        'opening' => $q->where('class', 'equity'), 'issue', 'count' => $q->whereIn('class', ['cogs', 'expense']), default => $q,
                    })),
                Forms\Components\TextInput::make('amount')->label(__('Landed cost amount'))->numeric()->minValue(0.01)->visible($is('landed_cost'))->required($is('landed_cost')),
                Forms\Components\ToggleButtons::make('allocation')->label(__('Allocate by'))->inline()->visible($is('landed_cost'))->default('value')
                    ->options(['value' => __('Value'), 'quantity' => __('Quantity')]),
                Forms\Components\TextInput::make('description')->label(__('Description'))->columnSpanFull(),
            ]),
            Forms\Components\Section::make(fn (Get $get) => $get('type') === 'landed_cost' ? __('Purchase bills to charge') : __('Items'))
                ->description(fn (Get $get) => match ($get('type')) {
                    'count' => __('Enter the counted quantity; the system compares it with the book quantity when posting.'),
                    'landed_cost' => __('The amount is spread over the stock items of these bills and raises their average cost.'),
                    'opening' => __('Quantity in the base unit and cost per base unit.'),
                    default => __('Quantities in the base unit. Cost is taken from the weighted average.'),
                })
                ->schema([
                    Forms\Components\Repeater::make('lines')->hiddenLabel()->columns(4)->minItems(1)->defaultItems(1)->addActionLabel(__('Add line'))->schema([
                        Forms\Components\Select::make('item_id')->label(__('Item'))->options(fn () => Options::items('stock'))->searchable()->live()
                            ->visible(fn (Get $get) => $get('../../type') !== 'landed_cost')->required(fn (Get $get) => $get('../../type') !== 'landed_cost')->columnSpan(2)
                            ->helperText(fn ($state, Get $get) => $state && ($i = Item::find($state)) ? __('On hand').': '.number_format((float) ($get('../../warehouse_id') ? $i->qtyIn((int) $get('../../warehouse_id')) : $i->qty_on_hand), 3).' '.$i->unit.' · '.__('Average cost').' '.number_format((float) $i->avg_cost, 4) : null),
                        Forms\Components\TextInput::make('quantity')->label(fn (Get $get) => $get('../../type') === 'count' ? __('Counted quantity') : __('Quantity'))
                            ->numeric()->required(fn (Get $get) => $get('../../type') !== 'landed_cost')->visible(fn (Get $get) => $get('../../type') !== 'landed_cost'),
                        Forms\Components\TextInput::make('unit_cost')->label(__('Unit cost'))->numeric()->minValue(0)->visible($isLine('opening'))->required($isLine('opening')),
                        Forms\Components\Select::make('invoice_id')->label(__('Purchase bill'))->visible($isLine('landed_cost'))->required($isLine('landed_cost'))->searchable()->columnSpan(3)
                            ->options(fn () => Invoice::where('company_id', Options::companyId())->where('kind', 'purchase')->where('status', 'posted')->with('party')->latest('date')->limit(300)->get()
                                ->mapWithKeys(fn ($i) => [$i->id => $i->number.' · '.$i->party?->name.' · '.$i->date->format('Y-m-d')])->all()),
                    ]),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\ViewEntry::make('smart')->hiddenLabel()->view('filament.components.smart-buttons')->state(fn (StockDocument $record) => array_values(array_filter([
                    $record->journalEntry ? ['icon' => 'heroicon-o-book-open', 'label' => __('Journal entry'), 'value' => $record->journalEntry->number, 'url' => JournalEntryResource::getUrl('view', ['record' => $record->journalEntry])] : null,
                ]))),
                Infolists\Components\ViewEntry::make('statusbar')->hiddenLabel()->view('filament.components.statusbar')
                    ->state(fn (StockDocument $record) => ['current' => $record->status, 'steps' => ['draft' => __('Draft'), 'posted' => __('Posted')] + ($record->status === 'cancelled' ? ['cancelled' => __('Cancelled')] : [])]),
            ]),
            Infolists\Components\Section::make(fn (StockDocument $record) => __(StockDocument::TYPES[$record->type]).' '.$record->display_number)->columns(4)->schema([
                Infolists\Components\TextEntry::make('date')->label(__('Accounting date'))->date('Y-m-d'),
                Infolists\Components\TextEntry::make('branch.name')->label(__('Branch')),
                Infolists\Components\TextEntry::make('warehouse.label')->label(__('Warehouse'))->placeholder('—'),
                Infolists\Components\TextEntry::make('toWarehouse.label')->label(__('To warehouse'))->visible(fn (StockDocument $r) => $r->type === 'transfer'),
                Infolists\Components\TextEntry::make('counterAccount.label')->label(__('Counter account'))->placeholder('—'),
                Infolists\Components\TextEntry::make('amount')->label(__('Landed cost amount'))->numeric(2, locale: 'en')->visible(fn (StockDocument $r) => $r->type === 'landed_cost'),
                Infolists\Components\TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make(__('Lines'))->schema([
                Infolists\Components\RepeatableEntry::make('lines')->hiddenLabel()->columns(5)->schema([
                    Infolists\Components\TextEntry::make('item.label')->label(__('Item'))->placeholder('—')->columnSpan(2),
                    Infolists\Components\TextEntry::make('quantity')->label(__('Quantity'))->numeric(3, locale: 'en'),
                    Infolists\Components\TextEntry::make('system_quantity')->label(__('Book quantity'))->numeric(3, locale: 'en')->placeholder('—')
                        ->visible(fn ($livewire) => $livewire->record->type === 'count'),
                    Infolists\Components\TextEntry::make('unit_cost')->label(__('Unit cost'))->numeric(4, locale: 'en')->placeholder('—'),
                    Infolists\Components\TextEntry::make('invoice.number')->label(__('Purchase bill'))->placeholder('—'),
                ]),
            ]),
            Infolists\Components\Section::make(__('Activity'))->collapsible()->schema([
                Infolists\Components\ViewEntry::make('chatter')->hiddenLabel()->view('filament.components.chatter')
                    ->state(fn (StockDocument $record) => AuditLog::with('user')->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->latest('id')->get()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('date', 'desc')->columns([
            Tables\Columns\TextColumn::make('display_number')->label(__('Number'))->searchable(['number'])->weight('bold'),
            Tables\Columns\TextColumn::make('type')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => __(StockDocument::TYPES[$state])),
            Tables\Columns\TextColumn::make('date')->label(__('Date'))->date('Y-m-d')->sortable(),
            Tables\Columns\TextColumn::make('warehouse.name_ar')->label(__('Warehouse'))->placeholder('—'),
            Tables\Columns\TextColumn::make('description')->label(__('Description'))->limit(40)->placeholder('—'),
            Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __(StockDocument::STATUSES[$state]))
                ->color(fn ($state) => match ($state) { 'posted' => 'success', 'cancelled' => 'danger', default => 'gray' }),
        ])->filters([
            Tables\Filters\SelectFilter::make('type')->label(__('Type'))->options(fn () => collect(StockDocument::TYPES)->map(fn ($l) => __($l))->all()),
            Tables\Filters\SelectFilter::make('status')->label(__('Status'))->options(fn () => collect(StockDocument::STATUSES)->map(fn ($l) => __($l))->all()),
        ])->actions([Tables\Actions\ViewAction::make()->iconButton(), Tables\Actions\EditAction::make()->iconButton()])
            ->recordUrl(fn (StockDocument $record) => static::getUrl('view', ['record' => $record]));
    }

    public static function headerActions(): array
    {
        return [
            Action::make('post')->label(__('Post'))->icon('heroicon-o-check-circle')->requiresConfirmation()
                ->visible(fn (StockDocument $record) => $record->status === 'draft' && auth()->user()->can(Perm::INVENTORY_POST))
                ->action(fn (StockDocument $record, $livewire) => static::attempt(fn () => app(InventoryService::class)->postDocument($record, auth()->user()), __('Posted')) && $livewire->refreshRecord()),
            Action::make('cancel')->label(__('Cancel'))->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (StockDocument $record) => $record->status === 'posted' && auth()->user()->can(Perm::INVENTORY_POST))
                ->form([Forms\Components\TextInput::make('reason')->label(__('Reason'))->required()])
                ->action(fn (StockDocument $record, array $data, $livewire) => static::attempt(fn () => app(InventoryService::class)->cancelDocument($record, auth()->user(), $data['reason']), __('Cancelled')) && $livewire->refreshRecord()),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockDocuments::route('/'),
            'create' => Pages\CreateStockDocument::route('/create'),
            'view' => Pages\ViewStockDocument::route('/{record}'),
            'edit' => Pages\EditStockDocument::route('/{record}/edit'),
        ];
    }
}
