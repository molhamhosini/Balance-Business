<?php

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\ReportService;
use Carbon\Carbon;
use Filament\Forms;
use Livewire\Attributes\Url;

/** Statement of financial position (IFRS / IAS 1): current and non-current split. */
class FinancialPosition extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';
    protected static ?int $navigationSort = 6;
    protected static ?string $slug = 'financial-position';
    protected static string $view = 'filament.pages.financial-position';

    #[Url] public ?string $compareTo = null;

    public static function getNavigationLabel(): string { return __('Statement of financial position'); }
    public function getTitle(): string { return __('Statement of financial position'); }

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
        $prev = $d['prev'] ? collect($d['prev']['sections'])->keyBy('key') : collect();
        foreach ($d['cur']['sections'] as $sec) {
            $ps = $prev[$sec['key']] ?? null;
            if (isset($sec['lines'])) {
                $rows[] = [$sec['label']];
                foreach ($sec['lines'] as $code => $l) {
                    $rows[] = ['  '.$l['label'], $l['amount'], $ps ? ($ps['lines'][$code]['amount'] ?? 0) : null];
                }
                $rows[] = [__('Total :label', ['label' => $sec['label']]), $sec['total'], $ps['total'] ?? null];
            } else {
                $rows[] = [$sec['label'], $sec['total'], $ps['total'] ?? null];
                $rows[] = [];
            }
        }

        return [['', substr((string) $this->to, 0, 10), substr((string) $this->compareTo, 0, 10)], $rows];
    }
}
