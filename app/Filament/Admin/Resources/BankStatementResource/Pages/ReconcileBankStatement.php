<?php

namespace App\Filament\Admin\Resources\BankStatementResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\BankStatementResource;
use App\Models\AuditLog;
use App\Models\BankStatementLine;
use App\Services\Accounting\BankingService;
use App\Support\Money;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;

/** Match statement lines with book lines; the summary explains any difference. */
class ReconcileBankStatement extends Page
{
    use InteractsWithRecord;

    protected static string $resource = BankStatementResource::class;
    protected static string $view = 'filament.pages.bank-reconcile';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(BankStatementResource::canViewAny(), 403);
    }

    public function getTitle(): string { return __('Bank reconciliation').' — '.$this->record->label; }

    protected function svc(): BankingService { return app(BankingService::class); }

    public function summary(): array { return $this->svc()->summary($this->record->fresh()); }

    /** Book lines that could match a statement line (same signed amount). */
    public function candidates(BankStatementLine $line, $open): array
    {
        $cents = Money::toCents($line->amount);

        return $open->filter(fn ($l) => Money::toCents($l->debit) - Money::toCents($l->credit) === $cents)
            ->mapWithKeys(fn ($l) => [$l->id => $l->entry->date->format('Y-m-d').' · '.$l->entry->number.' · '.str($l->description ?: $l->entry->description)->limit(40)])->all();
    }

    public function matchLine(int $lineId, $journalLineId): void
    {
        $line = $this->record->lines()->findOrFail($lineId);
        $this->guarded(fn () => $this->svc()->match($line, $journalLineId ? (int) $journalLineId : null, auth()->user()));
    }

    public function unmatch(int $lineId): void { $this->matchLine($lineId, null); }

    private function guarded(callable $fn): void
    {
        if ($this->record->status === 'reconciled') {
            Notification::make()->warning()->title(__('This statement is already reconciled.'))->send();

            return;
        }
        try {
            $fn();
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $open = fn () => $this->record->status === 'open';

        return [
            Actions\Action::make('auto')->label(__('Auto-match'))->icon('heroicon-o-sparkles')->color('gray')->visible($open)
                ->action(function () {
                    $n = $this->svc()->autoMatch($this->record);
                    Notification::make()->success()->title(__(':n lines matched.', ['n' => $n]))->send();
                }),
            Actions\Action::make('finish')->label(__('Mark as reconciled'))->icon('heroicon-o-check-badge')->visible($open)->requiresConfirmation()
                ->action(function () {
                    $s = $this->summary();
                    if ($s['difference'] !== 0 || $s['statement_check'] !== 0) {
                        Notification::make()->danger()->title(__('Rejected'))->body(__('The difference must be zero before closing the reconciliation.'))->send();

                        return;
                    }
                    $this->record->update(['status' => 'reconciled']);
                    AuditLog::record('reconciled', $this->record, ['status' => 'open'], ['status' => 'reconciled']);
                    Notification::make()->success()->title(__('Reconciled'))->send();
                }),
            Actions\Action::make('reopen')->label(__('Reopen'))->icon('heroicon-o-lock-open')->color('gray')->visible(fn () => $this->record->status === 'reconciled')
                ->action(fn () => $this->record->update(['status' => 'open'])),
        ];
    }
}
