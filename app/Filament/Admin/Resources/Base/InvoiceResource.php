<?php

namespace App\Filament\Admin\Resources\Base;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Filament\Admin\Resources\JournalEntryResource;
use App\Filament\Admin\Resources\VoucherResource;
use App\Filament\Admin\Support\Options;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\TaxCode;
use App\Models\Voucher;
use App\Services\Accounting\EInvoiceService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\VoucherService;
use App\Support\Money;
use App\Support\Perm;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Sales invoices / credit notes and purchase bills / debit notes (one model, a menu each). */
abstract class InvoiceResource extends Resource
{
    use ChecksPermissions, NotifiesAccountingErrors;

    protected static ?string $model = Invoice::class;
    protected static ?string $recordTitleAttribute = 'number';
    protected static ?string $viewPermission = Perm::INVOICES_VIEW;
    protected static ?string $createPermission = Perm::INVOICES_DRAFT;

    /** sale | purchase */
    abstract public static function side(): string;

    abstract public static function partyResource(): string;

    public static function kinds(): array { return static::side() === 'sale' ? ['sale', 'sale_return', 'quotation'] : ['purchase', 'purchase_return', 'purchase_order']; }

    /** The offer document of this side: quotation (sales) or purchase order (purchases). */
    public static function offerKind(): string { return static::side() === 'sale' ? 'quotation' : 'purchase_order'; }

    public static function isEditable(Model $record): bool { return $record->status === 'draft' || ($record instanceof Invoice && $record->isQuotation() && $record->status === 'sent'); }

    public static function statusLabels(): array
    {
        return ['draft' => __('Draft'), 'cancelled' => __('Cancelled'), 'paid' => __('Paid'), 'partial' => __('Partially paid'), 'not_paid' => __('Not paid'), 'sent' => __('Sent'), 'converted' => __('Converted to invoice')];
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->whereIn('kind', static::kinds());
        $allowed = auth()->user()->allowedBranchIds(Options::companyId());

        return $allowed ? $q->whereIn('branch_id', $allowed) : $q;
    }

    public static function kindLabel(string $kind): string { return __(Invoice::KINDS[$kind]); }

    public static function form(Form $form): Form
    {
        $sale = static::side() === 'sale';
        $revenue = fn () => Options::postableAccounts(fn ($q) => $q->whereIn('class', $sale ? ['revenue'] : ['cogs', 'expense', 'asset'])->where('is_cash', false)->where('is_bank', false));

        return $form->schema([
            Forms\Components\Section::make()->columns(4)->schema([
                Forms\Components\ToggleButtons::make('kind')->label(__('Document'))->inline()->required()->columnSpan(2)
                    ->options(fn (?Invoice $record) => collect(static::kinds())
                        ->filter(fn ($k) => ! $record?->exists || $record->isQuotation() === in_array($k, Invoice::OFFER_KINDS, true))
                        ->mapWithKeys(fn ($k) => [$k => static::kindLabel($k)])->all())
                    ->colors([static::side() => 'primary', static::side().'_return' => 'warning', 'quotation' => 'info', 'purchase_order' => 'info'])
                    ->default(fn () => in_array(request('kind'), static::kinds(), true) ? request('kind') : static::side())->live()
                    ->disabled(fn (?Invoice $record) => (bool) $record?->original_invoice_id || $record?->isQuotation() || (bool) $record?->quotation_id)->dehydrated(),
                Forms\Components\ToggleButtons::make('payment')->label(__('Payment'))->inline()->required()
                    ->options(['credit' => __('On credit'), 'cash' => __('Cash')])->default('credit')->live(),
                Forms\Components\Select::make('cash_account_id')->label(__('Cash / bank'))->options(fn () => Options::cashAndBank())->searchable()
                    ->visible(fn (Get $get) => $get('payment') === 'cash')->required(fn (Get $get) => $get('payment') === 'cash'),
                Forms\Components\Select::make('party_id')->label($sale ? __('Customer') : __('Supplier'))->required()->searchable()->columnSpan(2)
                    ->options(fn () => Party::where('company_id', Options::companyId())->where('type', $sale ? 'customer' : 'supplier')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($p) => [$p->id => $p->label])->all())
                    ->default(fn () => request('party'))->live()
                    ->afterStateUpdated(function ($state, Get $get, Forms\Set $set) {
                        $tax = $state ? Party::whereKey($state)->value('tax_code_id') : null;
                        if ($tax) {
                            foreach (array_keys($get('lines') ?? []) as $k) {
                                $set("lines.$k.tax_code_id", $tax);
                            }
                        }
                    })
                    ->createOptionForm(fn () => [
                        Forms\Components\TextInput::make('name_ar')->label(__('Name (Arabic)'))->required()->maxLength(200),
                        Forms\Components\TextInput::make('name_en')->label(__('Name (English)'))->maxLength(200),
                        Forms\Components\TextInput::make('trn')->label(__('TRN'))->length(15)->numeric(),
                        Forms\Components\Select::make('tax_code_id')->label(__('Tax classification'))->options(fn () => Options::taxCodes())->required()
                            ->default(fn () => TaxCode::where('company_id', Options::companyId())->where('code', 'SR')->value('id')),
                        Forms\Components\TextInput::make('phone')->label(__('Phone'))->tel(),
                        Forms\Components\TextInput::make('email')->label(__('Email'))->email(),
                        Forms\Components\TextInput::make('address')->label(__('Address')),
                        Forms\Components\TextInput::make('payment_terms_days')->label(__('Payment terms (days)'))->numeric()->default(30)->required(),
                    ])
                    ->createOptionUsing(fn (array $data) => app(\App\Services\Accounting\PartyService::class)->create(\Filament\Facades\Filament::getTenant(), $data + ['type' => $sale ? 'customer' : 'supplier'], auth()->user())->id)
                    ->helperText(function (Get $get) {
                        $p = $get('party_id') ? Party::find($get('party_id')) : null;

                        return $p ? __('Balance').': '.Money::format(abs($p->balance())).($p->trn ? ' · '.__('TRN').' '.$p->trn : '').(Money::toCents($p->credit_limit) > 0 ? ' · '.__('Credit limit').' '.Money::format($p->credit_limit) : '') : null;
                    }),
                Forms\Components\Select::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches())->default(fn () => array_key_first(Options::branches()))->required(),
                Forms\Components\TextInput::make('supplier_invoice_number')->label(__('Supplier invoice no.'))->visible(fn (Get $get) => ! $sale && $get('kind') !== 'purchase_order')->maxLength(60)
                    ->placeholder(fn (Get $get) => $get('kind') === 'purchase' ? app(\App\Services\Accounting\SequenceService::class)->peekSupplierInvoice(Options::companyId(), (int) date('Y', strtotime($get('date') ?: 'now'))) : null)
                    ->helperText(fn (Get $get) => $get('kind') === 'purchase' ? __('Leave empty to number automatically.') : null),
                Forms\Components\DatePicker::make('date')->label(fn (Get $get) => match ($get('kind')) { 'quotation' => __('Quotation date'), 'purchase_order' => __('Order date'), default => __('Invoice date') })->default(now())->required()->native(false)->displayFormat('Y-m-d'),
                Forms\Components\DatePicker::make('due_date')->label(fn (Get $get) => match ($get('kind')) { 'quotation' => __('Valid until'), 'purchase_order' => __('Expected delivery'), default => __('Due date') })->native(false)->displayFormat('Y-m-d')
                    ->placeholder(fn (Get $get) => in_array($get('kind'), Invoice::OFFER_KINDS, true) ? __(':d days', ['d' => \App\Services\Accounting\InvoiceService::QUOTATION_VALID_DAYS]) : __('From payment terms'))
                    ->visible(fn (Get $get) => $get('payment') === 'credit' || in_array($get('kind'), Invoice::OFFER_KINDS, true)),
                Forms\Components\Select::make('original_invoice_id')->label(__('Original invoice'))->searchable()
                    ->visible(fn (Get $get) => str_ends_with((string) $get('kind'), '_return'))
                    ->options(fn (Get $get) => Invoice::where('company_id', Options::companyId())->where('kind', static::side())->where('status', 'posted')
                        ->when($get('party_id'), fn ($q, $p) => $q->where('party_id', $p))->latest('date')->limit(200)->pluck('number', 'id')->all()),
                Forms\Components\Select::make('currency')->label(__('Currency'))->options(fn () => Options::currencies())->default('AED')->required()->live(),
                Forms\Components\TextInput::make('fx_rate')->label(__('Exchange rate (AED per unit)'))->numeric()->minValue(0.000001)
                    ->visible(fn (Get $get) => $get('currency') && $get('currency') !== 'AED')
                    ->placeholder(fn (Get $get) => static::ratePlaceholder($get))->helperText(__('Leave empty to use the rate of the invoice date.')),
                Forms\Components\TextInput::make('description')->label(__('Description'))->columnSpanFull(),
            ]),
            Forms\Components\Section::make(__('Invoice lines'))->schema([
                Forms\Components\Repeater::make('lines')->hiddenLabel()->required()->minItems(1)->reorderable()->cloneable()
                    ->addActionLabel(__('Add a line'))->columns(12)->defaultItems(1)->live(debounce: 500)
                    ->schema([
                        Forms\Components\Select::make('item_id')->label(__('Item'))->options(fn () => Options::items())->searchable()->live()->columnSpan(3)
                            ->afterStateUpdated(fn ($state, Get $get, Forms\Set $set) => static::fillFromItem($state, $get, $set)),
                        Forms\Components\Select::make('account_id')->label(__('Account'))->options($revenue)->searchable()->required()->columnSpan(3)
                            ->default(fn () => Options::companyId() ? \App\Models\Account::where('company_id', Options::companyId())->where('code', $sale ? '4111' : '5111')->value('id') : null),
                        Forms\Components\TextInput::make('description')->label(__('Description'))->required()->columnSpan(4),
                        Forms\Components\Select::make('cost_center_id')->label(__('Cost center'))->options(fn () => Options::costCenters())->columnSpan(2),
                        Forms\Components\TextInput::make('quantity')->label(__('Qty'))->numeric()->default(1)->required()->columnSpan(2),
                        Forms\Components\TextInput::make('unit')->label(__('Unit'))->placeholder(__('kg'))->columnSpan(1)->live(onBlur: true)
                            ->datalist(fn (Get $get) => ($i = static::lineItem($get)) ? array_merge([$i->unit], $i->units->pluck('name')->all()) : [])
                            ->afterStateUpdated(function ($state, Get $get, Forms\Set $set) use ($sale) {
                                $i = static::lineItem($get);
                                if ($sale && $i && ($last = static::lastSalePrice($get('../../party_id'), $i->id, $state ?: $i->unit, $get('../../currency')))) {
                                    $set('unit_price', $last['price']);
                                } elseif ($sale && $i) {
                                    $u = $i->units->firstWhere('name', $state);
                                    $set('unit_price', $u && (float) $u->sale_price ? $u->sale_price : round((float) $i->sale_price * (float) $i->factorOf($state), 4));
                                }
                            }),
                        Forms\Components\TextInput::make('unit_price')->label(__('Unit price'))->numeric()->required()->columnSpan(2)
                            ->helperText(function (Get $get) use ($sale) {
                                $last = $sale && $get('item_id') ? static::lastSalePrice($get('../../party_id'), (int) $get('item_id'), $get('unit') ?: null, $get('../../currency')) : null;

                                return $last ? __('Last price to this customer: :p (:d)', ['p' => rtrim(rtrim(number_format($last['price'], 4, '.', ''), '0'), '.'), 'd' => $last['date']]) : null;
                            }),
                        Forms\Components\Select::make('tax_code_id')->label(__('Tax'))->options(fn () => Options::taxCodes())->columnSpan(2)
                            ->default(fn (Get $get) => ($get('../../party_id') ? Party::whereKey($get('../../party_id'))->value('tax_code_id') : null)
                                ?? TaxCode::where('company_id', Options::companyId())->where('code', 'SR')->value('id')),
                        Forms\Components\Select::make('warehouse_id')->label(__('Warehouse'))->options(fn () => Options::warehouses())->columnSpan(3)
                            ->visible(fn (Get $get) => static::lineItem($get)?->isStock() ?? false)->placeholder(__('Branch warehouse')),
                    ]),
                Forms\Components\Placeholder::make('totals')->hiddenLabel()->content(fn (Get $get) => static::totalsPreview($get('lines') ?? [], $get('currency') ?: 'AED')),
            ]),
        ]);
    }

    public static function lineItem(Get $get): ?\App\Models\Item
    {
        return $get('item_id') ? \App\Models\Item::with('units')->find($get('item_id')) : null;
    }

    /** Selecting an item fills account, description, unit, price and tax (party classification wins). */
    /**
     * Last price this customer was charged for the item (posted sales invoices, same currency),
     * optionally for a given unit. Prices differ per customer, so this beats the item's list price.
     *
     * @return array{price: float, unit: ?string, date: string}|null
     */
    public static function lastSalePrice($partyId, ?int $itemId, ?string $unit = null, ?string $currency = null): ?array
    {
        if (! $partyId || ! $itemId) {
            return null;
        }
        $l = \App\Models\InvoiceLine::query()->join('invoices as i', 'i.id', '=', 'invoice_lines.invoice_id')
            ->where('i.company_id', Options::companyId())->where('i.party_id', $partyId)->where('i.kind', 'sale')->where('i.status', 'posted')
            ->where('i.currency', $currency ?: 'AED')->where('invoice_lines.item_id', $itemId)
            ->when($unit, fn ($q, $u) => $q->where('invoice_lines.unit', $u))
            ->orderByDesc('i.date')->orderByDesc('i.id')->orderByDesc('invoice_lines.id')
            ->first(['invoice_lines.unit_price', 'invoice_lines.unit', 'i.date']);

        return $l ? ['price' => (float) $l->unit_price, 'unit' => $l->unit, 'date' => substr((string) $l->date, 0, 10)] : null;
    }

    public static function fillFromItem($itemId, Get $get, Forms\Set $set): void
    {
        $i = $itemId ? \App\Models\Item::find($itemId) : null;
        if (! $i) {
            return;
        }
        $sale = static::side() === 'sale';
        $tenant = \Filament\Facades\Filament::getTenant();
        $account = $sale ? $i->sales_account_id : ($i->isStock()
            ? ($tenant->isPeriodic() ? $tenant->systemAccount('cogs')?->id : ($i->inventory_account_id ?? $tenant->systemAccount('inventory')?->id))
            : $i->purchase_account_id);
        if ($account) {
            $set('account_id', $account);
        }
        $set('description', $i->name);
        $set('unit', $i->unit);
        if ($sale && ($last = static::lastSalePrice($get('../../party_id'), $i->id, null, $get('../../currency')))) {
            $set('unit', $last['unit'] ?: $i->unit);
            $set('unit_price', $last['price']);
        } elseif ($sale) {
            $set('unit_price', (float) $i->sale_price);
        } elseif ($i->isStock() && (float) $i->avg_cost) {
            $set('unit_price', round((float) $i->avg_cost, 4));
        }
        $partyTax = $get('../../party_id') ? Party::whereKey($get('../../party_id'))->value('tax_code_id') : null;
        if (! $partyTax && $i->tax_code_id) {
            $set('tax_code_id', $i->tax_code_id);
        }
    }

    public static function ratePlaceholder(Get $get): ?string
    {
        try {
            return (string) \App\Models\ExchangeRate::rateFor(Options::companyId(), $get('currency'), \Illuminate\Support\Carbon::parse($get('date') ?: now())->toDateString());
        } catch (\Throwable) {
            return __('No rate entered for this date');
        }
    }

    public static function totalsPreview(array $lines, string $currency = 'AED'): \Illuminate\Support\HtmlString
    {
        $sub = $tax = 0;
        $svc = app(InvoiceService::class);
        foreach ($lines as $l) {
            if (! is_numeric($l['quantity'] ?? null) || ! is_numeric($l['unit_price'] ?? null)) {
                continue;
            }
            [$n, , $t] = $svc->lineAmounts($l['quantity'], $l['unit_price'], ! empty($l['tax_code_id']) ? TaxCode::find($l['tax_code_id']) : null);
            $sub += $n;
            $tax += $t - $n;
        }

        return new \Illuminate\Support\HtmlString('<div class="bb-totals"><div><span>'.e(__('Untaxed amount')).'</span><b>'.Money::format($sub).'</b></div><div><span>'.e(__('VAT')).'</span><b>'.Money::format($tax).'</b></div><div class="grand"><span>'.e(__('Total')).'</span><b>'.Money::format($sub + $tax).' '.e($currency).'</b></div></div>');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Grid::make(2)->schema([
                Infolists\Components\ViewEntry::make('smart')->hiddenLabel()->view('filament.components.smart-buttons')->state(fn (Invoice $record) => static::smartButtons($record)),
                Infolists\Components\ViewEntry::make('statusbar')->hiddenLabel()->view('filament.components.statusbar')->state(fn (Invoice $record) => $record->isQuotation() ? [
                    'current' => $record->status,
                    'steps' => ['draft' => __('Draft'), 'sent' => __('Sent')] + ($record->status === 'cancelled' ? ['cancelled' => __('Cancelled')] : ['converted' => $record->isPurchaseOrder() ? __('Converted to bill') : __('Converted to invoice')]),
                ] : [
                    'current' => $record->status === 'posted' ? $record->paymentState() : $record->status,
                    'steps' => ['draft' => __('Draft'), 'posted' => __('Posted')] + ($record->status === 'cancelled' ? ['cancelled' => __('Cancelled')] : ($record->payment === 'credit' ? ['not_paid' => __('Not paid'), 'partial' => __('Partially paid'), 'paid' => __('Paid')] : ['paid' => __('Paid')])),
                ]),
            ]),
            Infolists\Components\Section::make(fn (Invoice $record) => static::kindLabel($record->kind).' '.$record->display_number)
                ->description(fn (Invoice $record) => $record->einvoice_status !== 'not_sent' ? __('E-invoice').': '.__(Invoice::EINVOICE[$record->einvoice_status]).($record->einvoice_reason ? ' — '.$record->einvoice_reason : '') : null)
                ->columns(4)->schema([
                    Infolists\Components\TextEntry::make('party.label')->label(fn (Invoice $record) => $record->isSale() ? __('Customer') : __('Supplier'))->weight('bold')->columnSpan(2)
                        ->url(fn (Invoice $record) => static::partyResource()::getUrl('view', ['record' => $record->party_id])),
                    Infolists\Components\TextEntry::make('date')->label(fn (Invoice $record) => $record->isPurchaseOrder() ? __('Order date') : ($record->isQuotation() ? __('Quotation date') : __('Invoice date')))->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('due_date')->label(fn (Invoice $record) => $record->isPurchaseOrder() ? __('Expected delivery') : ($record->isQuotation() ? __('Valid until') : __('Due date')))->date('Y-m-d')->placeholder('—'),
                    Infolists\Components\TextEntry::make('party.trn')->label(__('TRN'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('payment')->label(__('Payment'))->formatStateUsing(fn ($state) => $state === 'cash' ? __('Cash') : __('On credit')),
                    Infolists\Components\TextEntry::make('branch.name')->label(__('Branch')),
                    Infolists\Components\TextEntry::make('supplier_invoice_number')->label(__('Supplier invoice no.'))->placeholder('—')->visible(fn (Invoice $record) => ! $record->isSale() && ! $record->isPurchaseOrder()),
                    Infolists\Components\TextEntry::make('original.number')->label(__('Original invoice'))->placeholder('—')->visible(fn (Invoice $record) => $record->isReturn()),
                    Infolists\Components\TextEntry::make('currency')->label(__('Currency'))->visible(fn (Invoice $record) => $record->currency !== 'AED')
                        ->formatStateUsing(fn (Invoice $record) => $record->currency.' · '.__('Rate').' '.rtrim(rtrim((string) $record->fx_rate, '0'), '.').' · '.Money::format($record->baseTotalCents()).' AED'),
                    Infolists\Components\TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
                ]),
            Infolists\Components\Section::make(fn (Invoice $record) => $record->isQuotation() ? __('Lines') : __('Invoice lines'))->schema([
                Infolists\Components\ViewEntry::make('lines_table')->hiddenLabel()->view('filament.components.invoice-lines')->state(fn (Invoice $record) => $record),
            ]),
            Infolists\Components\Section::make(__('Journal items'))->collapsible()->collapsed()->visible(fn (Invoice $record) => $record->journalEntry !== null)->schema([
                Infolists\Components\ViewEntry::make('entry_lines')->hiddenLabel()->view('filament.components.entry-lines')->state(fn (Invoice $record) => $record->journalEntry?->lines()->with(['account', 'taxCode'])->get()),
            ]),
            Infolists\Components\Section::make(__('Activity'))->collapsible()->schema([
                Infolists\Components\ViewEntry::make('chatter')->hiddenLabel()->view('filament.components.chatter')
                    ->state(fn (Invoice $record) => AuditLog::with('user')->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->latest('id')->get()),
            ]),
        ]);
    }

    protected static function smartButtons(Invoice $r): array
    {
        $payments = $r->allocations()->whereNotNull('voucher_id')->count();
        $notes = $r->returns()->where('status', '!=', 'cancelled')->count();

        return array_values(array_filter([
            $r->journalEntry ? ['icon' => 'heroicon-o-book-open', 'label' => __('Journal entry'), 'value' => $r->journalEntry->number, 'url' => JournalEntryResource::getUrl('view', ['record' => $r->journalEntry])] : null,
            $payments ? ['icon' => 'heroicon-o-banknotes', 'label' => __('Settlements'), 'value' => $payments, 'url' => VoucherResource::getUrl('view', ['record' => $r->allocations()->whereNotNull('voucher_id')->value('voucher_id')])] : null,
            $notes ? ['icon' => 'heroicon-o-receipt-refund', 'label' => $r->isSale() ? __('Credit notes') : __('Debit notes'), 'value' => $notes, 'url' => static::getUrl('view', ['record' => $r->returns()->where('status', '!=', 'cancelled')->value('id')])] : null,
            $r->original ? ['icon' => 'heroicon-o-document-text', 'label' => __('Original invoice'), 'value' => $r->original->number, 'url' => static::getUrl('view', ['record' => $r->original])] : null,
            $r->quotation ? ['icon' => 'heroicon-o-document-duplicate', 'label' => $r->quotation->isPurchaseOrder() ? __('Purchase order') : __('Quotation'), 'value' => $r->quotation->number, 'url' => static::getUrl('view', ['record' => $r->quotation])] : null,
            ($conv = $r->isQuotation() ? $r->convertedInvoices()->latest('id')->first() : null) ? ['icon' => 'heroicon-o-document-currency-dollar', 'label' => $r->isPurchaseOrder() ? __('Bill') : __('Invoice'), 'value' => $conv->display_number, 'url' => static::getUrl('view', ['record' => $conv])] : null,
            ['icon' => 'heroicon-o-printer', 'label' => match (true) { $r->isPurchaseOrder() => __('Purchase order'), $r->isQuotation() => __('Quotation'), $r->isSale() => __('Tax invoice'), default => __('Print') }, 'value' => '', 'url' => route('invoice.print', $r)],
        ]));
    }

    public static function table(Table $table): Table
    {
        $sale = static::side() === 'sale';

        return $table->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('display_number')->label(__('Number'))->searchable(['number', 'supplier_invoice_number'])->weight('bold')
                    ->description(fn (Invoice $record) => $record->supplier_invoice_number),
                Tables\Columns\TextColumn::make('kind')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => match (true) { $state === 'quotation' => __('Quotation'), $state === 'purchase_order' => __('Purchase order'), $state === 'sale_return' => __('Credit note'), $state === 'purchase_return' => __('Debit note'), default => __('Invoice') })
                    ->color(fn ($state) => in_array($state, Invoice::OFFER_KINDS, true) ? 'gray' : (str_ends_with($state, '_return') ? 'warning' : 'info')),
                Tables\Columns\TextColumn::make('party.name_ar')->formatStateUsing(\App\Support\Localized::name('party'))->label($sale ? __('Customer') : __('Supplier'))->searchable(),
                Tables\Columns\TextColumn::make('date')->label(__('Date'))->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('due_date')->label(__('Due date'))->date('Y-m-d')->sortable()->toggleable()
                    ->color(fn (Invoice $record) => $record->due_date?->isPast() && $record->openCents() > 0 ? 'danger' : null),
                Tables\Columns\TextColumn::make('subtotal')->label(__('Untaxed'))->numeric(2, locale: 'en')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tax_total')->label(__('VAT'))->numeric(2, locale: 'en')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('total')->label(__('Total'))->numeric(2, locale: 'en')->alignEnd()->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->numeric(2, locale: 'en')),
                Tables\Columns\TextColumn::make('open')->label(__('Amount due'))->alignEnd()->state(fn (Invoice $record) => $record->isQuotation() ? '—' : Money::format($record->openCents())),
                Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (Invoice $record) => $record->status === 'posted' ? $record->paymentState() : $record->status)
                    ->formatStateUsing(fn ($state) => static::statusLabels()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'paid', 'converted' => 'success', 'partial' => 'warning', 'not_paid' => 'danger', 'sent' => 'info', default => 'gray' }),
                Tables\Columns\TextColumn::make('einvoice_status')->label(__('E-invoice'))->badge()->visible($sale)->toggleable()
                    ->formatStateUsing(fn ($state) => __(Invoice::EINVOICE[$state] ?? $state))
                    ->color(fn ($state) => match ($state) { 'accepted' => 'success', 'sent' => 'info', 'rejected' => 'danger', default => 'gray' }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('party_id')->label($sale ? __('Customer') : __('Supplier'))->searchable()
                    ->options(fn () => Party::where('company_id', Options::companyId())->where('type', $sale ? 'customer' : 'supplier')->orderBy('code')->get()->mapWithKeys(fn ($p) => [$p->id => $p->label])->all()),
                Tables\Filters\SelectFilter::make('status')->label(__('Status'))->options(fn () => collect($sale ? Invoice::STATUSES : array_slice(Invoice::STATUSES, 0, 3))->map(fn ($l) => __($l))->all()),
                Tables\Filters\SelectFilter::make('payment')->label(__('Payment'))->options(['credit' => __('On credit'), 'cash' => __('Cash')]),
                Tables\Filters\SelectFilter::make('branch_id')->label(__('Branch'))->options(fn () => Options::branches()),
                Tables\Filters\Filter::make('overdue')->label(__('Overdue'))->query(fn (Builder $query) => $query->where('status', 'posted')->where('payment', 'credit')->where('due_date', '<', now()->toDateString())),
            ])
            ->groups([
                Tables\Grouping\Group::make('party.name_ar')->label($sale ? __('Customer') : __('Supplier')),
                Tables\Grouping\Group::make('status')->label(__('Status'))->getTitleFromRecordUsing(fn ($record) => __(Invoice::STATUSES[$record->status])),
                Tables\Grouping\Group::make('date')->label(__('Month'))->date()->getTitleFromRecordUsing(fn ($record) => $record->date->format('Y-m')),
            ])
            ->actions([Tables\Actions\ViewAction::make()->iconButton(), Tables\Actions\EditAction::make()->iconButton()])
            ->recordUrl(fn (Invoice $record) => static::getUrl('view', ['record' => $record]));
    }

    // ---- Header actions (view page) ----

    public static function postAction(): Action
    {
        return Action::make('post')->label(__('Confirm'))->icon('heroicon-o-check-circle')->color('primary')
            ->visible(fn (Invoice $record) => $record->status === 'draft' && ! $record->isQuotation() && auth()->user()->can(Perm::INVOICES_POST))
            ->requiresConfirmation()->modalDescription(__('Confirming numbers the invoice and posts its journal entry. After that it can only be corrected with a credit note.'))
            ->action(function (Invoice $record, $livewire) {
                $svc = app(InvoiceService::class);
                if (static::attempt(fn () => $svc->post($record, auth()->user()), __('Invoice posted'))) {
                    foreach ($svc->warnings as $w) {
                        Notification::make()->warning()->title(__('Warning'))->body($w)->persistent()->send();
                    }
                    $livewire->refreshRecord();
                }
            });
    }

    /** Quotation actions: mark as sent, convert to a draft sales invoice, cancel. */
    public static function quotationActions(): array
    {
        $open = fn (?Model $record) => $record instanceof Invoice && $record->isQuotation() && in_array($record->status, ['draft', 'sent'], true) && auth()->user()->can(Perm::INVOICES_DRAFT);

        return [
            Action::make('convertQuotation')->label(fn (Invoice $record) => $record->isPurchaseOrder() ? __('Convert to bill') : __('Convert to invoice'))->icon('heroicon-o-document-check')->color('primary')->visible($open)
                ->requiresConfirmation()->modalDescription(fn (Invoice $record) => $record->isPurchaseOrder() ? __('Creates a draft purchase bill with the same lines. Review it, then confirm it.') : __('Creates a draft sales invoice with the same lines. Review it, then confirm it.'))
                ->action(function (Invoice $record, $livewire) {
                    $inv = null;
                    if (static::attempt(function () use ($record, &$inv) { $inv = app(InvoiceService::class)->convertQuotation($record, auth()->user()); }, $record->isPurchaseOrder() ? __('Bill created from the purchase order') : __('Invoice created from the quotation'))) {
                        $livewire->redirect(static::getUrl('edit', ['record' => $inv]), navigate: true);
                    }
                }),
            Action::make('markSent')->label(__('Mark as sent'))->icon('heroicon-o-paper-airplane')->color('gray')
                ->visible(fn (?Model $record) => $open($record) && $record->status === 'draft')
                ->action(fn (Invoice $record, $livewire) => static::attempt(fn () => app(InvoiceService::class)->markQuotationSent($record, auth()->user()), __('Saved')) && $livewire->refreshRecord()),
            Action::make('cancelQuotation')->label(fn (Invoice $record) => $record->isPurchaseOrder() ? __('Cancel purchase order') : __('Cancel quotation'))->icon('heroicon-o-x-circle')->color('danger')->visible($open)
                ->form([Forms\Components\TextInput::make('reason')->label(__('Reason'))])
                ->action(fn (Invoice $record, array $data, $livewire) => static::attempt(fn () => app(InvoiceService::class)->cancelQuotation($record, auth()->user(), $data['reason'] ?? null), $record->isPurchaseOrder() ? __('Purchase order cancelled') : __('Quotation cancelled')) && $livewire->refreshRecord()),
        ];
    }

    public static function registerPaymentAction(): Action
    {
        return Action::make('registerPayment')->label(__('Register payment'))->icon('heroicon-o-banknotes')->color('success')
            ->visible(fn (Invoice $record) => $record->status === 'posted' && $record->openCents() > 0 && auth()->user()->can(Perm::VOUCHERS_DRAFT))
            ->fillForm(fn (Invoice $record) => ['amount' => Money::fromCents($record->openCents()), 'date' => now()->toDateString(), 'payment_method' => 'cash'])
            ->form([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('amount')->label(__('Amount'))->numeric()->required()->minValue(0.01),
                    Forms\Components\DatePicker::make('date')->label(__('Payment date'))->required()->native(false)->displayFormat('Y-m-d'),
                    Forms\Components\Select::make('cash_account_id')->label(__('Cash / bank'))->options(fn () => Options::cashAndBank())->required()->searchable(),
                    Forms\Components\Select::make('payment_method')->label(__('Payment method'))->options(fn () => collect(Voucher::METHODS)->map(fn ($l) => __($l))->all())->required(),
                    Forms\Components\TextInput::make('cheque_number')->label(__('Cheque number')),
                ]),
            ])
            ->action(function (Invoice $record, array $data, $livewire) {
                $v = null;
                if (static::attempt(function () use ($record, $data, &$v) { $v = app(InvoiceService::class)->registerPayment($record, $data, auth()->user(), app(VoucherService::class)); })) {
                    Notification::make()->success()->title($v->status === 'posted' ? __('Payment registered') : __('Payment voucher created as a draft; it settles the invoice once posted.'))
                        ->actions([\Filament\Notifications\Actions\Action::make('open')->label($v->display_number)->url(VoucherResource::getUrl('view', ['record' => $v]))])->send();
                    $livewire->refreshRecord();
                }
            });
    }

    /** Close a small residual (rounding, bank charge) or an exchange difference left after payment. */
    public static function writeOffAction(): Action
    {
        return Action::make('writeOff')->label(__('Write off difference'))->icon('heroicon-o-scissors')->color('gray')
            ->visible(fn (Invoice $record) => $record->status === 'posted' && $record->openCents() > 0 && ($record->currency !== 'AED' || $record->openCents() <= 500) && auth()->user()->can(Perm::INVOICES_CANCEL))
            ->modalDescription(fn (Invoice $record) => __('Closes the remaining :a AED to exchange gain / loss.', ['a' => Money::format($record->openCents())]))
            ->form([Forms\Components\TextInput::make('reason')->label(__('Reason'))->required()])
            ->action(fn (Invoice $record, array $data, $livewire) => static::attempt(fn () => app(InvoiceService::class)->writeOffDifference($record, auth()->user(), $data['reason']), __('Saved')) && $livewire->refreshRecord());
    }

    public static function creditNoteAction(): Action
    {
        return Action::make('creditNote')->label(fn (Invoice $record) => $record->isSale() ? __('Credit note') : __('Debit note'))->icon('heroicon-o-receipt-refund')->color('warning')
            ->visible(fn (Invoice $record) => $record->status === 'posted' && ! $record->isReturn() && auth()->user()->can(Perm::INVOICES_DRAFT))
            ->requiresConfirmation()->modalDescription(__('Creates a draft return with the same lines. Adjust quantities, then confirm it.'))
            ->action(function (Invoice $record, $livewire) {
                $cn = null;
                if (static::attempt(function () use ($record, &$cn) { $cn = app(InvoiceService::class)->makeReturn($record, auth()->user()); })) {
                    $livewire->redirect(static::getUrl('edit', ['record' => $cn]), navigate: true);
                }
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label(__('Cancel'))->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (Invoice $record) => $record->status === 'posted' && auth()->user()->can(Perm::INVOICES_CANCEL))
            ->form([Forms\Components\TextInput::make('reason')->label(__('Reason'))->required()])
            ->modalDescription(__('The journal entry is reversed and the number kept as cancelled. E-invoiced documents must be corrected with a credit note instead.'))
            ->action(fn (Invoice $record, array $data, $livewire) => static::attempt(fn () => app(InvoiceService::class)->cancel($record, auth()->user(), $data['reason']), __('Invoice cancelled')) && $livewire->refreshRecord());
    }

    /** Send the posted sales document to the customer: email (saved address) or WhatsApp (saved phone). */
    /** Print or download the invoice in one language: Arabic or English, never mixed. */
    public static function printActions(): \Filament\Actions\ActionGroup
    {
        $actions = [];
        foreach (\App\Support\InvoiceText::LANGS as $lang => $name) {
            $actions[] = Action::make('print_'.$lang)->label(__('Print').' — '.$name)->icon('heroicon-o-printer')
                ->url(fn (Invoice $record) => route('invoice.print', [$record, 'lang' => $lang]))->openUrlInNewTab();
            $actions[] = Action::make('pdf_'.$lang)->label(__('Download PDF').' — '.$name)->icon('heroicon-o-document-arrow-down')
                ->url(fn (Invoice $record) => route('invoice.pdf', [$record, 'lang' => $lang]));
        }

        return \Filament\Actions\ActionGroup::make($actions)->label(__('Print'))->icon('heroicon-o-printer')->button()->color('gray');
    }

    public static function sendActions(): \Filament\Actions\ActionGroup
    {
        $sendable = fn (?Model $record) => $record instanceof Invoice && $record->isSendable();
        $sharing = fn () => app(\App\Services\Accounting\InvoiceSharing::class);

        return \Filament\Actions\ActionGroup::make([
            Action::make('sendEmail')->label(__('Send by email'))->icon('heroicon-o-envelope')->visible($sendable)
                ->fillForm(fn (Invoice $record) => ['lang' => 'ar', 'to' => $record->party?->email, 'message' => $sharing()->defaultMessage($record)])
                ->form([
                    Forms\Components\ToggleButtons::make('lang')->label(__('Invoice language'))->inline()->options(\App\Support\InvoiceText::LANGS)->default('ar')->required()->live()
                        ->afterStateUpdated(fn ($state, Forms\Set $set, $livewire) => ($livewire->record ?? null) instanceof Invoice ? $set('message', $sharing()->defaultMessage($livewire->record, (string) $state)) : null),
                    Forms\Components\TextInput::make('to')->label(__('To'))->email()->required()
                        ->helperText(fn (Invoice $record) => $record->party?->email ? null : __('No email saved for this customer. It is saved on the customer card when you send.')),
                    Forms\Components\TextInput::make('cc')->label(__('Cc'))->placeholder('a@x.com, b@y.com'),
                    Forms\Components\Textarea::make('message')->label(__('Message'))->rows(5)->required()->extraInputAttributes(['dir' => 'auto']),
                ])
                ->modalSubmitActionLabel(__('Send'))
                ->action(function (Invoice $record, array $data, $livewire) use ($sharing) {
                    try {
                        $sharing()->email($record, $data['to'], $data['cc'] ?? null, $data['message'], $data['lang'] ?? 'ar');
                        if ($record->party && blank($record->party->email)) {
                            $record->party->update(['email' => $data['to']]);
                        }
                        Notification::make()->success()->title(__('Invoice sent to :to', ['to' => $data['to']]))->send();
                        $livewire->refreshRecord();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title(__('The email could not be sent'))->body(__('Check the mail settings (MAIL_*) in the .env file.').' '.$e->getMessage())->persistent()->send();
                    }
                }),
            Action::make('sendWhatsapp')->label(__('Send by WhatsApp'))->icon('heroicon-o-chat-bubble-left-right')->visible($sendable)
                ->fillForm(fn (Invoice $record) => ['lang' => 'ar', 'phone' => $record->party?->phone, 'message' => $sharing()->defaultMessage($record)])
                ->form([
                    Forms\Components\ToggleButtons::make('lang')->label(__('Invoice language'))->inline()->options(\App\Support\InvoiceText::LANGS)->default('ar')->required()->live()
                        ->afterStateUpdated(fn ($state, Forms\Set $set, $livewire) => ($livewire->record ?? null) instanceof Invoice ? $set('message', $sharing()->defaultMessage($livewire->record, (string) $state)) : null),
                    Forms\Components\TextInput::make('phone')->label(__('WhatsApp number'))->tel()->required()->helperText(__('Example: 0501234567 or +971501234567')),
                    Forms\Components\Textarea::make('message')->label(__('Message'))->rows(5)->required()->extraInputAttributes(['dir' => 'auto']),
                ])
                ->modalDescription(__('WhatsApp opens in a new tab with the message and the invoice link ready. Press send there.'))
                ->modalSubmitActionLabel(__('Open WhatsApp'))
                ->action(function (Invoice $record, array $data, $livewire) use ($sharing) {
                    if ($record->party && blank($record->party->phone)) {
                        $record->party->update(['phone' => $data['phone']]);
                    }
                    $url = $sharing()->whatsappUrl($record, $data['phone'], $data['message'], $data['lang'] ?? 'ar');
                    $livewire->refreshRecord();
                    $livewire->js('window.open('.json_encode($url).', "_blank")');
                }),
        ])->label(__('Send'))->icon('heroicon-o-paper-airplane')->button()->color('success');
    }

    public static function einvoiceActions(): \Filament\Actions\ActionGroup
    {
        $issuable = fn (?Model $record) => $record instanceof Invoice && $record->status === 'posted' && $record->isSale();

        return \Filament\Actions\ActionGroup::make([
            Action::make('xml')->visible($issuable)->label(__('Download UBL XML (PINT AE)'))->icon('heroicon-o-code-bracket')->url(fn (Invoice $record) => route('invoice.einvoice', [$record, 'xml']))->openUrlInNewTab(),
            Action::make('json')->visible($issuable)->label(__('Download JSON'))->icon('heroicon-o-code-bracket-square')->url(fn (Invoice $record) => route('invoice.einvoice', [$record, 'json']))->openUrlInNewTab(),
            Action::make('einvoiceStatus')->label(__('Update e-invoice status'))->icon('heroicon-o-signal')
                ->visible(fn (?Model $record) => $issuable($record) && auth()->user()->can(Perm::EINVOICE_MANAGE))
                ->fillForm(fn (Invoice $record) => ['status' => $record->einvoice_status === 'not_sent' ? 'sent' : $record->einvoice_status, 'reference' => $record->einvoice_reference])
                ->form([
                    Forms\Components\Select::make('status')->label(__('Status'))->required()->live()->options(fn () => collect(Invoice::EINVOICE)->except('not_sent')->map(fn ($l) => __($l))->all()),
                    Forms\Components\TextInput::make('reference')->label(__('Provider reference')),
                    Forms\Components\TextInput::make('reason')->label(__('Rejection reason'))->visible(fn (Get $get) => $get('status') === 'rejected')->required(fn (Get $get) => $get('status') === 'rejected'),
                ])
                ->action(fn (Invoice $record, array $data, $livewire) => static::attempt(fn () => app(EInvoiceService::class)->setStatus($record, $data['status'], auth()->user(), $data['reference'] ?? null, $data['reason'] ?? null), __('Saved')) && $livewire->refreshRecord()),
        ])->label(__('E-invoice'))->icon('heroicon-o-cloud-arrow-up')->color('gray')->button();
    }
}
