<?php

namespace App\Filament\Admin\Pages;

class CostCenterReport extends TableReport
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';
    protected static ?int $navigationSort = 9;
    protected static bool $costCenterFilter = false;

    public static function getNavigationLabel(): string { return __('Cost center profitability'); }
    public function getTitle(): string { return __('Cost center profitability'); }

    public function tables(): array
    {
        $d = $this->reports()->costCenterProfit($this->company(), $this->from, $this->to, $this->branchId());
        $rows = [];
        foreach ($d['rows'] as $r) {
            $c = $r['center'];
            $rows[] = ['cells' => [
                $c ? ['t' => $c->label, 'url' => IncomeStatement::getUrl(['from' => $this->from, 'to' => $this->to, 'branch' => $this->branch, 'cc' => $c->id])] : __('Not assigned'),
                $r['revenue'], $r['cogs'], $r['gross'], $r['expense'], ['t' => \App\Support\Money::format($r['net']), 'n' => true, 'cls' => $r['net'] < 0 ? 'bb-bad' : ''],
                ['t' => $r['margin'] === null ? '' : $r['margin'].'%', 'n' => true],
            ]];
        }
        $t = $d['totals'];
        $rows[] = ['class' => 'total', 'cells' => [__('Total'), $t['revenue'], $t['cogs'], $t['gross'], $t['expense'], ['t' => \App\Support\Money::format($t['net']), 'n' => true], ['t' => $t['revenue'] ? round($t['net'] * 100 / $t['revenue'], 1).'%' : '', 'n' => true]]];

        return [[
            'description' => __('Revenue, cost of sales and expenses posted with each cost center. Click a center for its income statement.'),
            'headers' => [__('Cost center'), __('Revenue'), __('Cost of sales'), __('Gross profit'), __('Expenses'), __('Net result'), __('Margin')],
            'numeric' => [1, 2, 3, 4, 5, 6], 'rows' => count($rows) > 1 ? $rows : [],
        ]];
    }
}
