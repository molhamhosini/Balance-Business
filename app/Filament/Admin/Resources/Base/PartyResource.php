<?php

namespace App\Filament\Admin\Resources\Base;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Pages\Aging;
use App\Filament\Admin\Pages\PartyStatement;
use App\Filament\Admin\Support\Options;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Party;
use App\Support\Money;
use App\Support\Perm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Customers and suppliers share one model (a sub-ledger on the control account); each has its own menu. */
abstract class PartyResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Party::class;
    protected static ?string $recordTitleAttribute = 'name_ar';
    protected static ?string $viewPermission = Perm::INVOICES_VIEW;
    protected static ?string $createPermission = Perm::PARTIES_MANAGE;

    /** customer | supplier */
    abstract public static function partyType(): string;

    abstract public static function invoiceResource(): string;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', static::partyType());
    }

    public static function getGloballySearchableAttributes(): array { return ['code', 'name_ar', 'name_en', 'trn', 'phone']; }

    public static function form(Form $form): Form
    {
        $customer = static::partyType() === 'customer';

        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\TextInput::make('name_ar')->label(__('Name (Arabic)'))->required()->maxLength(200)->columnSpan(2),
                Forms\Components\TextInput::make('code')->label(__('Code'))->placeholder(__('Automatic'))->maxLength(20)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('company_id', Options::companyId())->where('type', static::partyType())),
                Forms\Components\TextInput::make('name_en')->label(__('Name (English)'))->maxLength(200)->columnSpan(2),
                Forms\Components\TextInput::make('trn')->label(__('TRN'))->maxLength(15)->minLength(15)->numeric()->helperText(__('15 digits, required on tax invoices for registered buyers')),
                Forms\Components\TextInput::make('phone')->label(__('Phone'))->tel(),
                Forms\Components\TextInput::make('email')->label(__('Email'))->email(),
                Forms\Components\Select::make('emirate')->label(__('Emirate'))->options(fn () => collect(Branch::EMIRATES)->map(fn ($l) => __($l))->all())->default('AUH'),
                Forms\Components\TextInput::make('address')->label(__('Address'))->columnSpan(2),
                Forms\Components\TextInput::make('country')->label(__('Country'))->default('AE')->maxLength(2)->required(),
            ]),
            Forms\Components\Section::make(__('Terms'))->columns(4)->schema([
                Forms\Components\TextInput::make('payment_terms_days')->label(__('Payment terms (days)'))->numeric()->minValue(0)->default($customer ? 30 : 30)->required(),
                Forms\Components\TextInput::make('credit_limit')->label(__('Credit limit'))->numeric()->minValue(0)->default(0)->helperText(__('0 = no limit'))->visible($customer),
                Forms\Components\Select::make('credit_limit_action')->label(__('When exceeded'))->options(['warn' => __('Warn'), 'block' => __('Block')])->default('warn')->visible($customer),
                Forms\Components\Toggle::make('is_related_party')->label(__('Related party'))->inline(false),
                Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true)->inline(false),
                Forms\Components\Textarea::make('notes')->label(__('Notes'))->columnSpanFull()->rows(2),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\ViewEntry::make('smart')->hiddenLabel()->view('filament.components.smart-buttons')->state(function (Party $record) {
                $inv = static::invoiceResource();
                $open = $record->invoices()->where('status', 'posted')->get()->filter(fn ($i) => $i->openCents() > 0)->count();

                return [
                    ['icon' => 'heroicon-o-document-text', 'label' => __('Invoices'), 'value' => $record->invoices()->where('status', '!=', 'cancelled')->count(), 'url' => $inv::getUrl('index', ['tableFilters' => ['party_id' => ['value' => $record->id]]])],
                    ['icon' => 'heroicon-o-clock', 'label' => __('Open invoices'), 'value' => $open, 'url' => $inv::getUrl('index', ['tableFilters' => ['party_id' => ['value' => $record->id]], 'activeTab' => 'open'])],
                    ['icon' => 'heroicon-o-scale', 'label' => __('Balance'), 'value' => Money::format(abs($record->balance())), 'url' => PartyStatement::getUrl(['party' => $record->id])],
                    ['icon' => 'heroicon-o-chart-bar', 'label' => __('Aging'), 'value' => '', 'url' => Aging::getUrl(['type' => $record->type])],
                ];
            }),
            Infolists\Components\Section::make(fn (Party $r) => $r->label)->columns(4)->schema([
                Infolists\Components\TextEntry::make('name_en')->label(__('Name (English)'))->placeholder('—'),
                Infolists\Components\TextEntry::make('trn')->label(__('TRN'))->placeholder('—'),
                Infolists\Components\TextEntry::make('phone')->label(__('Phone'))->placeholder('—'),
                Infolists\Components\TextEntry::make('email')->label(__('Email'))->placeholder('—'),
                Infolists\Components\TextEntry::make('address')->label(__('Address'))->placeholder('—')->columnSpan(2),
                Infolists\Components\TextEntry::make('emirate')->label(__('Emirate'))->formatStateUsing(fn ($state) => __(Branch::EMIRATES[$state] ?? $state))->placeholder('—'),
                Infolists\Components\TextEntry::make('account.label')->label(__('Control account')),
                Infolists\Components\TextEntry::make('payment_terms_days')->label(__('Payment terms (days)')),
                Infolists\Components\TextEntry::make('credit_limit')->label(__('Credit limit'))->numeric(2, locale: 'en')->visible(fn (Party $r) => $r->type === 'customer'),
                Infolists\Components\IconEntry::make('is_related_party')->label(__('Related party'))->boolean(),
                Infolists\Components\IconEntry::make('is_active')->label(__('Active'))->boolean(),
            ]),
            Infolists\Components\Section::make(__('Activity'))->collapsible()->collapsed()->schema([
                Infolists\Components\ViewEntry::make('chatter')->hiddenLabel()->view('filament.components.chatter')
                    ->state(fn (Party $r) => AuditLog::with('user')->where('auditable_type', $r->getMorphClass())->where('auditable_id', $r->id)->latest('id')->get()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label(__('Code'))->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('name_ar')->label(__('Name'))->searchable(['name_ar', 'name_en'])->description(fn (Party $record) => $record->name_en),
                Tables\Columns\TextColumn::make('trn')->label(__('TRN'))->searchable()->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->label(__('Phone'))->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('payment_terms_days')->label(__('Terms'))->suffix(' '.__('days'))->toggleable(),
                Tables\Columns\TextColumn::make('balance')->label(__('Balance'))->alignEnd()
                    ->state(fn (Party $record) => Money::format(abs($record->balance())).($record->balance() === 0 ? '' : ' '.($record->balance() > 0 ? __('Dr') : __('Cr')))),
                Tables\Columns\IconColumn::make('is_related_party')->label(__('Related'))->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('Active')),
                Tables\Filters\TernaryFilter::make('is_related_party')->label(__('Related party')),
            ])
            ->actions([Tables\Actions\ViewAction::make()->iconButton(), Tables\Actions\EditAction::make()->iconButton()])
            ->recordUrl(fn (Party $record) => static::getUrl('view', ['record' => $record]));
    }
}
