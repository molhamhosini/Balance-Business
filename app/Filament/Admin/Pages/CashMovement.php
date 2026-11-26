<?php

namespace App\Filament\Admin\Pages;

use Livewire\Attributes\Url;
use Filament\Forms;

class CashMovement extends TableReport
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 11;
    protected static bool $costCenterFilter = false;

    #[Url] public ?bool $daily = false;

    public static function getNavigationLabel(): string { return __('Cash & bank movement'); }
    public function getTitle(): string { return __('Cash & bank movement'); }

    protected function formState(): array { return parent::formState() + ['daily' => $this->daily]; }

    protected function filterFields(): array
    {
        return array_merge(parent::filterFields(), [Forms\Components\Toggle::make('daily')->label(__('Show daily movement'))->live()->inline(false)]);
    }

    public function tables(): array
    {
        $d = $this->reports()->cashMovement($this->company(), $this->from, $this->to, $this->branchId());
        $rows = [];
        foreach ($d['rows'] as $r) {
            $rows[] = ['class' => $this->daily ? 'head' : '', 'cells' => [
                ['t' => $r['account']->label, 'url' => Ledger::getUrl(['account' => $r['account']->id, 'from' => $this->from, 'to' => $this->to, 'branch' => $this->branch])],
                $r['opening'], $r['in'], $r['out'], ['t' => \App\Support\Money::format($r['closing']), 'n' => true],
            ]];
            if ($this->daily) {
                foreach ($r['days'] as $day) {
                    $rows[] = ['cells' => ['    '.$day['date'], '', $day['in'], $day['out'], ['t' => \App\Support\Money::format($day['balance']), 'n' => true]]];
                }
            }
        }
        $t = $d['totals'];
        if ($rows) {
            $rows[] = ['class' => 'total', 'cells' => [__('Total'), $t['opening'], $t['in'], $t['out'], ['t' => \App\Support\Money::format($t['closing']), 'n' => true]]];
        }

        return [['headers' => [__('Cash / bank account'), __('Opening balance'), __('Receipts'), __('Payments'), __('Closing balance')], 'numeric' => [1, 2, 3, 4], 'rows' => $rows]];
    }
}
