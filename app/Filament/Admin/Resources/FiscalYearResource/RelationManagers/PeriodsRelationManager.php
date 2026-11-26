<?php

namespace App\Filament\Admin\Resources\FiscalYearResource\RelationManagers;

use App\Filament\Admin\Concerns\NotifiesAccountingErrors;
use App\Models\FiscalPeriod;
use App\Services\Accounting\PeriodService;
use App\Support\Perm;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PeriodsRelationManager extends RelationManager
{
    use NotifiesAccountingErrors;

    protected static string $relationship = 'periods';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string { return __('Accounting periods'); }

    public function isReadOnly(): bool { return false; }

    public function table(Table $table): Table
    {
        return $table->defaultSort('number')->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('#'),
                Tables\Columns\TextColumn::make('label')->label(__('Period')),
                Tables\Columns\TextColumn::make('start_date')->label(__('From'))->date('Y-m-d'),
                Tables\Columns\TextColumn::make('end_date')->label(__('To'))->date('Y-m-d'),
                Tables\Columns\TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state === 'open' ? __('Open') : __('Closed'))->color(fn ($state) => $state === 'open' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('closedBy.name')->label(__('Closed by'))->placeholder('—'),
                Tables\Columns\TextColumn::make('closed_at')->label(__('Closed at'))->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('close')->label(__('Close'))->icon('heroicon-o-lock-closed')->color('warning')
                    ->visible(fn (FiscalPeriod $r) => $r->isOpen() && auth()->user()->can(Perm::PERIODS_CLOSE))->requiresConfirmation()
                    ->action(fn (FiscalPeriod $r) => static::attempt(fn () => app(PeriodService::class)->close($r, auth()->user()), __('Period closed'))),
                Tables\Actions\Action::make('reopen')->label(__('Reopen'))->icon('heroicon-o-lock-open')->color('danger')
                    ->visible(fn (FiscalPeriod $r) => ! $r->isOpen() && auth()->user()->can(Perm::PERIODS_REOPEN))
                    ->form([Forms\Components\TextInput::make('reason')->label(__('Reason'))->required()])
                    ->action(fn (FiscalPeriod $r, array $data) => static::attempt(fn () => app(PeriodService::class)->reopen($r, auth()->user(), $data['reason']), __('Period reopened'))),
            ]);
    }
}
