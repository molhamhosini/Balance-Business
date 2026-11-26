<?php

namespace App\Filament\Admin\Pages;

use App\Support\Money;

class CashFlow extends TableReport
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';
    protected static ?int $navigationSort = 11;
    protected static bool $costCenterFilter = false;

    public static function getNavigationLabel(): string { return __('Cash flow statement'); }
    public function getTitle(): string { return __('Cash flow statement'); }

    public function mount(): void
    {
        $this->from ??= now()->startOfYear()->toDateString();
        parent::mount();
    }

    public function tables(): array
    {
        $d = $this->reports()->cashFlow($this->company(), $this->from, $this->to, $this->branchId());
        $m = fn (int $v, string $cls = '') => ['t' => Money::format($v), 'n' => true, 'cls' => $cls];
        $rows = [['class' => 'head', 'cells' => [__('Operating activities'), '']]];
        $rows[] = ['cells' => ['  '.__('Net profit for the period'), $m($d['net_income'])]];
        if ($d['depreciation']) {
            $rows[] = ['cells' => ['  '.__('Add: depreciation'), $m($d['depreciation'])]];
        }
        foreach ($d['sections']['operating'] as $r) {
            $rows[] = ['cells' => ['  '.__('Change in').' '.$r['label'], $m($r['amount'])]];
        }
        $rows[] = ['class' => 'total', 'cells' => [__('Net cash from operating activities'), $m($d['operating'])]];
        foreach (['investing' => __('Investing activities'), 'financing' => __('Financing activities')] as $k => $label) {
            $rows[] = ['class' => 'head', 'cells' => [$label, '']];
            foreach ($d['sections'][$k] as $r) {
                $rows[] = ['cells' => ['  '.$r['label'], $m($r['amount'])]];
            }
            $rows[] = ['class' => 'total', 'cells' => [__('Net cash from').' '.$label, $m($d[$k])]];
        }
        $rows[] = ['class' => 'total', 'cells' => [__('Net change in cash'), $m($d['net_change'])]];
        $rows[] = ['cells' => [__('Cash and banks at start of period'), $m($d['cash_open'])]];
        $rows[] = ['class' => 'total', 'cells' => [__('Cash and banks at end of period'), $m($d['cash_close'])]];
        $rows[] = ['cells' => [__('Check'), $d['check'] === 0 ? ['t' => '✓ '.__('Balanced'), 'cls' => 'bb-ok', 'n' => true] : $m($d['check'], 'bb-bad')]];

        return [['description' => __('Indirect method: net profit adjusted for depreciation and the change in working capital, then investing and financing movements.'),
            'headers' => [__('Description'), __('Amount')], 'numeric' => [1], 'rows' => $rows]];
    }
}
