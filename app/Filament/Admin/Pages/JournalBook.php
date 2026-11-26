<?php

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\ReportService;
use App\Support\Money;
use App\Support\Perm;

class JournalBook extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.journal-book';
    protected static ?string $reportPermission = Perm::REPORTS_LIMITED;

    public static function getNavigationLabel(): string { return __('Journal book'); }
    public function getTitle(): string { return __('Journal book'); }

    public function getData()
    {
        return app(ReportService::class)->journal($this->company(), $this->from, $this->to, $this->branchId());
    }

    protected function exportRows(): array
    {
        $rows = [];
        foreach ($this->getData() as $e) {
            foreach ($e->lines as $l) {
                $rows[] = [$e->date->toDateString(), $e->number, $e->description, $l->account->code, $l->account->name, Money::toCents($l->debit), Money::toCents($l->credit)];
            }
        }

        return [[__('Date'), __('Number'), __('Description'), __('Code'), __('Account'), __('Debit'), __('Credit')], $rows];
    }
}
