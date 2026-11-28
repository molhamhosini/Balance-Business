<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Pages\ItemCard;
use App\Filament\Admin\Resources\ItemResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Warehouse;
use App\Services\Accounting\InventoryService;
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
use Illuminate\Database\Eloquent\Model;

/** Item card: code, barcode, units with conversion factors, price, tax and accounts (requirements §5). */
class ItemResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Item::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'name_ar';
    protected static ?string $viewPermission = Perm::INVENTORY_VIEW;
    protected static ?string $createPermission = Perm::INVENTORY_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Inventory'); }
    public static function getModelLabel(): string { return __('Item'); }
    public static function getPluralModelLabel(): string { return __('Items'); }
    public static function getGloballySearchableAttributes(): array { return ['code', 'barcode', 'name_ar', 'name_en']; }

    public static function canDelete(Model $record): bool { return false; }

    public static function form(Form $form): Form
    {
        $hasMoves = fn (?Item $record) => $record && $record->moves()->exists();

        return $form->schema([
            Forms\Components\Section::make()->columns(4)->schema([
                Forms\Components\TextInput::make('code')->label(__('Code'))->required()->maxLength(30)->default(fn () => Item::nextCode(Options::companyId()))
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('company_id', Options::companyId())),
                Forms\Components\TextInput::make('barcode')->label(__('Barcode')),
                Forms\Components\TextInput::make('name_ar')->label(__('Arabic name'))->required(),
                Forms\Components\TextInput::make('name_en')->label(__('English name')),
                Forms\Components\ToggleButtons::make('type')->label(__('Type'))->inline()->live()->required()->default('stock')
                    ->options(fn () => collect(Item::TYPES)->map(fn ($l) => __($l))->all())->disabled($hasMoves)->dehydrated(),
                Forms\Components\TextInput::make('unit')->label(__('Base unit'))->required()->default('كغ')->maxLength(20)->disabled($hasMoves)->dehydrated()
                    ->helperText(__('Stock is kept and costed in this unit.')),
                Forms\Components\TextInput::make('sale_price')->label(__('Sale price (per base unit)'))->numeric()->default(0),
                Forms\Components\Select::make('tax_code_id')->label(__('Tax code'))->options(fn () => Options::taxCodes())
                    ->helperText(__('Used when the customer/supplier card has no tax classification.')),
                Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true),
            ]),
            Forms\Components\Section::make(__('Accounts'))->columns(2)->collapsible()->schema([
                Forms\Components\Select::make('sales_account_id')->label(__('Sales account'))->options(fn () => Options::postableAccounts(fn ($q) => $q->where('class', 'revenue')))->searchable()->required(),
                Forms\Components\Select::make('inventory_account_id')->label(__('Inventory account'))->visible(fn (Get $get) => $get('type') === 'stock')
                    ->options(fn () => Options::postableAccounts(fn ($q) => $q->where('code', 'like', '114%')))->searchable()
                    ->default(fn () => \Filament\Facades\Filament::getTenant()->systemAccount('inventory')?->id),
                Forms\Components\Select::make('cogs_account_id')->label(__('Cost of sales account'))->visible(fn (Get $get) => $get('type') === 'stock')
                    ->options(fn () => Options::postableAccounts(fn ($q) => $q->where('class', 'cogs')))->searchable()
                    ->default(fn () => \Filament\Facades\Filament::getTenant()->systemAccount('cogs')?->id),
                Forms\Components\Select::make('purchase_account_id')->label(__('Purchase / expense account'))->visible(fn (Get $get) => $get('type') === 'service')
                    ->options(fn () => Options::postableAccounts(fn ($q) => $q->whereIn('class', ['cogs', 'expense'])))->searchable(),
            ]),
            Forms\Components\Section::make(__('Other units'))->description(__('E.g. carton = 10 kg. Invoices can use any of these units; stock is converted to the base unit.'))->collapsible()->schema([
                Forms\Components\Repeater::make('units')->relationship()->hiddenLabel()->columns(4)->defaultItems(0)->addActionLabel(__('Add unit'))->schema([
                    Forms\Components\TextInput::make('name')->label(__('Unit'))->required()->maxLength(20),
                    Forms\Components\TextInput::make('factor')->label(__('Base units per unit'))->numeric()->minValue(0.001)->required(),
                    Forms\Components\TextInput::make('barcode')->label(__('Barcode')),
                    Forms\Components\TextInput::make('sale_price')->label(__('Sale price'))->numeric(),
                ]),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\ViewEntry::make('smart')->hiddenLabel()->view('filament.components.smart-buttons')->state(fn (Item $record) => $record->isStock() ? [
                ['icon' => 'heroicon-o-cube', 'label' => __('On hand').' ('.$record->unit.')', 'value' => number_format((float) $record->qty_on_hand, 3), 'url' => ItemCard::getUrl(['item' => $record->id])],
                ['icon' => 'heroicon-o-banknotes', 'label' => __('Stock value'), 'value' => Money::format($record->stock_value), 'url' => ItemCard::getUrl(['item' => $record->id])],
                ['icon' => 'heroicon-o-scale', 'label' => __('Average cost'), 'value' => number_format((float) $record->avg_cost, 4), 'url' => ItemCard::getUrl(['item' => $record->id])],
            ] : []),
            Infolists\Components\Section::make(fn (Item $record) => $record->label)->columns(4)->schema([
                Infolists\Components\TextEntry::make('type')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => __(Item::TYPES[$state])),
                Infolists\Components\TextEntry::make('unit')->label(__('Base unit')),
                Infolists\Components\TextEntry::make('barcode')->label(__('Barcode'))->placeholder('—'),
                Infolists\Components\TextEntry::make('sale_price')->label(__('Sale price'))->numeric(2, locale: 'en'),
                Infolists\Components\TextEntry::make('taxCode.label')->label(__('Tax code'))->placeholder('—'),
                Infolists\Components\TextEntry::make('salesAccount.label')->label(__('Sales account'))->placeholder('—'),
                Infolists\Components\TextEntry::make('inventoryAccount.label')->label(__('Inventory account'))->placeholder('—')->visible(fn (Item $record) => $record->isStock()),
                Infolists\Components\TextEntry::make('cogsAccount.label')->label(__('Cost of sales account'))->placeholder('—')->visible(fn (Item $record) => $record->isStock()),
                Infolists\Components\TextEntry::make('purchaseAccount.label')->label(__('Purchase / expense account'))->placeholder('—')->visible(fn (Item $record) => ! $record->isStock()),
                Infolists\Components\TextEntry::make('units_list')->label(__('Other units'))->columnSpanFull()->placeholder('—')
                    ->state(fn (Item $record) => $record->units->map(fn ($u) => $u->name.' = '.rtrim(rtrim((string) $u->factor, '0'), '.').' '.$record->unit)->implode(' · ')),
            ]),
            Infolists\Components\Section::make(__('Stock by warehouse'))->visible(fn (Item $record) => $record->isStock())->schema([
                Infolists\Components\RepeatableEntry::make('by_warehouse')->hiddenLabel()->columns(2)->contained(false)
                    ->state(fn (Item $record) => Warehouse::where('company_id', $record->company_id)->orderBy('code')->get()
                        ->map(fn ($w) => ['warehouse' => $w->label, 'qty' => number_format((float) InventoryService::qty(InventoryService::milli($record->qtyIn($w->id))), 3).' '.$record->unit])
                        ->filter(fn ($r) => (float) str_replace(',', '', $r['qty']) != 0)->values()->all())
                    ->schema([
                        Infolists\Components\TextEntry::make('warehouse')->hiddenLabel(),
                        Infolists\Components\TextEntry::make('qty')->hiddenLabel()->weight('bold'),
                    ]),
            ]),
            Infolists\Components\Section::make(__('Activity'))->collapsible()->collapsed()->schema([
                Infolists\Components\ViewEntry::make('chatter')->hiddenLabel()->view('filament.components.chatter')
                    ->state(fn (Item $record) => AuditLog::with('user')->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->latest('id')->get()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')->columns([
            Tables\Columns\TextColumn::make('code')->label(__('Code'))->weight('bold')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name_ar')->formatStateUsing(\App\Support\Localized::name())->label(__('Name'))->searchable(['name_ar', 'name_en', 'barcode'])->description(fn (Item $r) => $r->barcode),
            Tables\Columns\TextColumn::make('type')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => __(Item::TYPES[$state]))->color(fn ($state) => $state === 'stock' ? 'info' : 'gray'),
            Tables\Columns\TextColumn::make('unit')->label(__('Unit')),
            Tables\Columns\TextColumn::make('sale_price')->label(__('Sale price'))->numeric(2, locale: 'en')->alignEnd(),
            Tables\Columns\TextColumn::make('qty_on_hand')->label(__('On hand'))->numeric(3, locale: 'en')->alignEnd()->sortable()
                ->color(fn ($state) => (float) $state < 0 ? 'danger' : null),
            Tables\Columns\TextColumn::make('avg_cost')->label(__('Average cost'))->numeric(4, locale: 'en')->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('stock_value')->label(__('Stock value'))->numeric(2, locale: 'en')->alignEnd()
                ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->numeric(2, locale: 'en')),
            Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Tables\Filters\SelectFilter::make('type')->label(__('Type'))->options(fn () => collect(Item::TYPES)->map(fn ($l) => __($l))->all()),
            Tables\Filters\TernaryFilter::make('is_active')->label(__('Active')),
        ])->actions([
            Tables\Actions\Action::make('card')->label(__('Item card'))->icon('heroicon-o-queue-list')->iconButton()->visible(fn (Item $r) => $r->isStock())
                ->url(fn (Item $r) => ItemCard::getUrl(['item' => $r->id])),
            Tables\Actions\EditAction::make()->iconButton(),
        ])->recordUrl(fn (Item $r) => static::getUrl('view', ['record' => $r]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListItems::route('/'),
            'create' => Pages\CreateItem::route('/create'),
            'view' => Pages\ViewItem::route('/{record}'),
            'edit' => Pages\EditItem::route('/{record}/edit'),
        ];
    }
}
