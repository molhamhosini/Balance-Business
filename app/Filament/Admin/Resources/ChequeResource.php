<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\ChecksPermissions;
use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Filament\Admin\Resources\ChequeResource\Pages;
use App\Filament\Admin\Support\Options;
use App\Models\Voucher;
use App\Services\Accounting\BankingService;
use App\Support\Perm;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Cheque register: received and issued cheques with due date and status (requirements §4). */
class ChequeResource extends Resource
{
    use ChecksPermissions, NotifiesAccountingErrors;

    protected static ?string $model = Voucher::class;
    protected static ?string $slug = 'cheques';
    protected static ?string $navigationIcon = 'heroicon-o-document-check';
    protected static ?int $navigationSort = 4;
    protected static ?string $viewPermission = Perm::VOUCHERS_VIEW;
    protected static ?string $createPermission = null;

    public static function canCreate(): bool { return false; }
    public static function getNavigationGroup(): ?string { return __('Cash & banks'); }
    public static function getModelLabel(): string { return __('Cheque'); }
    public static function getPluralModelLabel(): string { return __('Cheques'); }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('cheque_status');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('company_id', Options::companyId())->whereIn('cheque_status', ['in_box', 'issued'])->where('cheque_due_date', '<=', now()->toDateString())->count();

        return $n ?: null;
    }

    public static function table(Table $table): Table
    {
        $act = fn (string $name, string $label, string $icon, string $color, array $from, bool $needsBank, bool $needsReason) => Tables\Actions\Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible(fn (Voucher $record) => in_array($record->cheque_status, $from, true) && auth()->user()->can(Perm::BANKING_MANAGE))
            ->form(array_values(array_filter([
                Forms\Components\DatePicker::make('date')->label(__('Date'))->default(now())->required()->native(false)->displayFormat('Y-m-d'),
                $needsBank ? Forms\Components\Select::make('bank')->label(__('Bank account'))->options(fn () => Options::postableAccounts(fn ($q) => $q->where('is_bank', true)))
                    ->default(fn (Voucher $record) => $record->cashAccount?->is_bank ? $record->cash_account_id : null)->required() : null,
                $needsReason ? Forms\Components\TextInput::make('reason')->label(__('Reason'))->required() : null,
            ])))
            ->action(fn (Voucher $record, array $data) => static::attempt(fn () => app(BankingService::class)->chequeAction($record, $name, auth()->user(), $data['date'], isset($data['bank']) ? (int) $data['bank'] : null, $data['reason'] ?? null), __('Saved')));

        return $table->defaultSort('cheque_due_date')
            ->columns([
                Tables\Columns\TextColumn::make('cheque_number')->label(__('Cheque number'))->weight('bold')->searchable(),
                Tables\Columns\TextColumn::make('type')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => $state === 'receipt' ? __('Received') : __('Issued'))
                    ->color(fn ($state) => $state === 'receipt' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('party_name')->label(__('Party'))->searchable(),
                Tables\Columns\TextColumn::make('cheque_bank')->label(__('Bank'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('cheque_due_date')->label(__('Due date'))->date('Y-m-d')->sortable()
                    ->color(fn (Voucher $record) => in_array($record->cheque_status, ['in_box', 'issued'], true) && $record->cheque_due_date?->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('total')->label(__('Amount'))->numeric(2, locale: 'en')->alignEnd()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('')->numeric(2, locale: 'en')),
                Tables\Columns\TextColumn::make('cheque_status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __(Voucher::CHEQUE_STATUSES[$state] ?? $state))
                    ->color(fn ($state) => match ($state) { 'deposited', 'cleared' => 'success', 'returned' => 'danger', default => 'warning' }),
                Tables\Columns\TextColumn::make('number')->label(__('Voucher'))->url(fn (Voucher $record) => VoucherResource::getUrl('view', ['record' => $record]))->color('primary'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label(__('Type'))->options(['receipt' => __('Received'), 'payment' => __('Issued')]),
                Tables\Filters\SelectFilter::make('cheque_status')->label(__('Status'))->options(fn () => collect(Voucher::CHEQUE_STATUSES)->map(fn ($l) => __($l))->all()),
                Tables\Filters\Filter::make('due')->label(__('Due'))->query(fn (Builder $query) => $query->whereIn('cheque_status', ['in_box', 'issued'])->where('cheque_due_date', '<=', now()->toDateString())),
            ])
            ->actions([
                $act('deposit', __('Deposit'), 'heroicon-o-building-library', 'success', ['in_box'], true, false),
                $act('clear', __('Cleared by bank'), 'heroicon-o-check-badge', 'success', ['issued'], true, false),
                $act('return', __('Returned'), 'heroicon-o-arrow-uturn-left', 'danger', ['in_box', 'deposited', 'issued'], false, true),
            ]);
    }

    public static function getPages(): array { return ['index' => Pages\ListCheques::route('/')]; }
}
