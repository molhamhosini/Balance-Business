<?php

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\ReportService;
use Carbon\Carbon;
use Filament\Forms;
use Livewire\Attributes\Url;

class BalanceSheet extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-building-library';
    protected static ?int $navigationSort = 5;
    protected static string $view = 'filament.pages.balance-sheet';

    #[Url] public ?string $compareTo = null;

    public static function getNavigationLabel(): string { return __('Balance sheet'); }
    public function getTitle(): string { return __('Balance sheet'); }

    public function mount(): void
    {
        $this->to ??= now()->toDateString();
        $this->compareTo ??= Carbon::parse($this->to)->subYear()->endOfYear()->toDateString();
        parent::mount();
    }

    protected function formState(): array { return ['to' => $this->to, 'compareTo' => $this->compareTo, 'branch' => $this->branch]; }

    protected function filterFields(): array
    {
        return [
            Forms\Components\DatePicker::make('to')->label(__('As of'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\DatePicker::make('compareTo')->label(__('Compare with'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\Select::make('branch')->label(__('Branch'))->options(fn () => \App\Filament\Admin\Support\Options::branches())->placeholder(__('All branches'))->live(),
        ];
    }

    public function getData(): array
    {
        $svc = $this->reports();

        return ['cur' => $svc->balanceSheet($this->company(), $this->to, $this->branchId()), 'prev' => $this->compareTo ? $svc->balanceSheet($this->company(), $this->compareTo, $this->branchId()) : null];
    }

    protected function exportRows(): array
    {
        $d = $this->getData();
        $rows = [];
        foreach (['asset' => __('Assets'), 'liability' => __('Liabilities'), 'equity' => __('Equity')] as $k => $label) {
            $rows[] = [$label];
            foreach ($d['cur']['groups'][$k] as $code => $g) {
                $rows[] = ['  '.$g['label'], $g['amount'], $d['prev']['groups'][$k][$code]['amount'] ?? null];
            }
            $rows[] = [__('Total').' '.$label, $d['cur']['totals'][$k], $d['prev']['totals'][$k] ?? null];
        }

        return [['', substr((string) $this->to, 0, 10), substr((string) $this->compareTo, 0, 10)], $rows];
    }
}
