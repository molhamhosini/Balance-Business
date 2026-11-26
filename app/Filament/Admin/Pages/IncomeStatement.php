<?php

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\ReportService;
use Carbon\Carbon;
use Filament\Forms;
use Livewire\Attributes\Url;

class IncomeStatement extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?int $navigationSort = 4;
    protected static string $view = 'filament.pages.income-statement';

    #[Url] public bool $compare = true;

    public static function getNavigationLabel(): string { return __('Income statement'); }
    public function getTitle(): string { return __('Income statement'); }

    protected function formState(): array { return parent::formState() + ['compare' => $this->compare]; }

    protected function filterFields(): array
    {
        return array_merge(parent::filterFields(), [Forms\Components\Toggle::make('compare')->label(__('Compare with previous period'))->live()->inline(false)]);
    }

    /** Previous period of the same length, ending the day before $from. */
    public function previousRange(): array
    {
        $f = Carbon::parse($this->from);
        $t = Carbon::parse($this->to);
        if ($f->isSameDay($f->copy()->startOfMonth()) && $t->isSameDay($t->copy()->endOfMonth())) {
            $months = $f->diffInMonths($t->copy()->addDay());
            $pf = $f->copy()->subMonthsNoOverflow((int) round($months));

            return [$pf->toDateString(), $f->copy()->subDay()->toDateString()];
        }
        $days = $f->diffInDays($t) + 1;

        return [$f->copy()->subDays($days)->toDateString(), $f->copy()->subDay()->toDateString()];
    }

    public function getData(): array
    {
        $svc = app(ReportService::class);
        $cur = $svc->incomeStatement($this->company(), $this->from, $this->to, $this->branchId());
        $prev = null;
        if ($this->compare) {
            [$pf, $pt] = $this->previousRange();
            $prev = $svc->incomeStatement($this->company(), $pf, $pt, $this->branchId()) + ['from' => $pf, 'to' => $pt];
        }

        return ['cur' => $cur, 'prev' => $prev];
    }

    protected function exportRows(): array
    {
        $d = $this->getData();
        $rows = [];
        foreach (['revenue' => __('Revenue'), 'cogs' => __('Cost of sales'), 'expense' => __('Operating expenses')] as $k => $label) {
            $rows[] = [$label, ''];
            foreach ($d['cur']['groups'][$k] as $code => $g) {
                $rows[] = ['  '.$g['label'], $g['amount'], $d['prev']['groups'][$k][$code]['amount'] ?? null];
            }
            $rows[] = [__('Total').' '.$label, $d['cur']['totals'][$k], $d['prev']['totals'][$k] ?? null];
            if ($k === 'cogs') {
                $rows[] = [__('Gross profit'), $d['cur']['gross'], $d['prev']['gross'] ?? null];
            }
        }
        $rows[] = [__('Net profit'), $d['cur']['net'], $d['prev']['net'] ?? null];

        return [['', __('Current period'), __('Previous period')], $rows];
    }
}
