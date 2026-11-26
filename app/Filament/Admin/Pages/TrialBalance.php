<?php

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\ReportService;
use Filament\Forms;
use Livewire\Attributes\Url;

class TrialBalance extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.trial-balance';

    #[Url] public int $level = 4;
    #[Url] public string $mode = 'balances';

    public static function getNavigationLabel(): string { return __('Trial balance'); }
    public function getTitle(): string { return __('Trial balance'); }

    protected function formState(): array { return parent::formState() + ['level' => $this->level, 'mode' => $this->mode]; }

    protected function filterFields(): array
    {
        return array_merge(parent::filterFields(), [
            Forms\Components\Select::make('level')->label(__('Level'))->options([1 => 1, 2 => 2, 3 => 3, 4 => 4])->selectablePlaceholder(false)->live(),
            Forms\Components\Select::make('mode')->label(__('Show'))->options(['balances' => __('Balances'), 'totals' => __('Totals')])->selectablePlaceholder(false)->live(),
        ]);
    }

    public function getData(): array
    {
        return $this->reports()->trialBalance($this->company(), $this->from, $this->to, (int) $this->level, $this->branchId());
    }

    protected function exportRows(): array
    {
        $d = $this->getData();
        $rows = $d['rows']->map(fn ($r) => [$r['account']->code, $r['account']->name, $r['opening_dr'], $r['opening_cr'], $r['pd'], $r['pc'], $r['closing_dr'], $r['closing_cr']])->all();
        $t = $d['totals'];
        $rows[] = ['', __('Total'), $t['opening_dr'], $t['opening_cr'], $t['pd'], $t['pc'], $t['closing_dr'], $t['closing_cr']];

        return [[__('Code'), __('Account'), __('Opening debit'), __('Opening credit'), __('Period debit'), __('Period credit'), __('Closing debit'), __('Closing credit')], $rows];
    }
}
