<?php

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\ReportService;
use App\Support\Perm;
use Filament\Forms;
use Livewire\Attributes\Url;

class Aging extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?int $navigationSort = 8;
    protected static bool $costCenterFilter = false;
    protected static string $view = 'filament.pages.aging';

    #[Url] public ?string $type = 'customer';

    public static function getNavigationLabel(): string { return __('Aging'); }
    public function getTitle(): string { return $this->type === 'supplier' ? __('Payables aging') : __('Receivables aging'); }

    public static function canAccess(): bool { return auth()->user()->can(Perm::INVOICES_VIEW); }

    public function mount(): void
    {
        $this->to ??= now()->toDateString();
        parent::mount();
    }

    protected function formState(): array { return ['to' => $this->to, 'branch' => $this->branch, 'type' => $this->type]; }

    protected function filterFields(): array
    {
        return [
            Forms\Components\ToggleButtons::make('type')->label(__('Type'))->inline()->live()->options(['customer' => __('Customers'), 'supplier' => __('Suppliers')])->columnSpan(2),
            Forms\Components\DatePicker::make('to')->label(__('As of'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\Select::make('branch')->label(__('Branch'))->options(fn () => \App\Filament\Admin\Support\Options::branches())->placeholder(__('All branches'))->live(),
        ];
    }

    public function getData(): array
    {
        return $this->reports()->aging($this->company(), $this->type === 'supplier' ? 'supplier' : 'customer', \Illuminate\Support\Carbon::parse($this->to)->toDateString(), $this->branchId());
    }

    public function buckets(): array
    {
        return ['current' => __('Not due')] + collect(ReportService::AGING_BUCKETS)->except('current')->mapWithKeys(fn ($l, $k) => [$k => $l.' '.__('days')])->all();
    }

    protected function exportRows(): array
    {
        $d = $this->getData();
        $keys = array_keys($this->buckets());
        $rows = [];
        foreach ($d['rows'] as $r) {
            $rows[] = array_merge([$r['party']->code, $r['party']->name], array_map(fn ($k) => $r[$k], $keys), [$r['unapplied'], $r['total']]);
        }
        $rows[] = array_merge(['', __('Total')], array_map(fn ($k) => $d['totals'][$k], $keys), [$d['totals']['unapplied'], $d['totals']['total']]);

        return [array_merge([__('Code'), __('Name')], array_values($this->buckets()), [__('Unapplied'), __('Total')]), $rows];
    }
}
