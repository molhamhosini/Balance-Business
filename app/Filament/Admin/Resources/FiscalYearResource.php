<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Filament\Admin\Resources\FiscalYearResource\Pages;
use App\Filament\Admin\Resources\FiscalYearResource\RelationManagers\PeriodsRelationManager;
use App\Models\FiscalYear;
use App\Services\Accounting\PeriodService;
use App\Support\Perm;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FiscalYearResource extends Resource
{
    use NotifiesAccountingErrors;

    protected static ?string $model = FiscalYear::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getModelLabel(): string { return __('Fiscal year'); }
    public static function getPluralModelLabel(): string { return __('Fiscal years & periods'); }

    public static function canViewAny(): bool { return auth()->user()->canAny([Perm::PERIODS_CLOSE, Perm::SETTINGS_MANAGE, Perm::REPORTS_VIEW]); }
    public static function canCreate(): bool { return auth()->user()->canAny([Perm::SETTINGS_MANAGE, Perm::YEAR_CLOSE]); }
    public static function canEdit(Model $record): bool { return true; }
    public static function canDelete(Model $record): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('start_date')->label(__('Start date'))->required()->helperText(__('12 monthly periods are created from this date. It does not have to be 1 January.'))->disabledOn('edit')->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\Placeholder::make('end_preview')->label(__('End date (12 months)'))
                ->content(fn (\Filament\Forms\Get $get) => $get('start_date') ? \Carbon\CarbonImmutable::parse($get('start_date'))->addMonthsNoOverflow(12)->subDay()->format('Y-m-d') : '—'),
            Forms\Components\TextInput::make('name')->label(__('Name')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('start_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('Name'))->weight('bold'),
                Tables\Columns\TextColumn::make('start_date')->label(__('Start date'))->date('Y-m-d'),
                Tables\Columns\TextColumn::make('end_date')->label(__('End date'))->date('Y-m-d'),
                Tables\Columns\TextColumn::make('open_periods')->label(__('Open periods'))->state(fn (FiscalYear $r) => $r->periods()->where('status', 'open')->count().' / 12'),
                Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state === 'open' ? __('Open') : __('Closed'))->color(fn ($state) => $state === 'open' ? 'success' : 'gray'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label(__('Periods')),
                Tables\Actions\Action::make('closeYear')->label(__('Close year'))->icon('heroicon-o-lock-closed')->color('danger')
                    ->visible(fn (FiscalYear $r) => ! $r->isClosed() && auth()->user()->can(Perm::YEAR_CLOSE))
                    ->requiresConfirmation()->modalDescription(__('Creates the closing entry to retained earnings, locks all periods and opens the next year.'))
                    ->action(fn (FiscalYear $r) => static::attempt(fn () => app(PeriodService::class)->closeYear($r, auth()->user()), __('Year closed'))),
            ]);
    }

    public static function getRelations(): array
    {
        return [PeriodsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFiscalYears::route('/'),
            'create' => Pages\CreateFiscalYear::route('/create'),
            'edit' => Pages\EditFiscalYear::route('/{record}/edit'),
        ];
    }
}
