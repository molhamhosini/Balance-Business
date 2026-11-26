<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\AssetResource;
use App\Models\Asset;
use App\Support\Perm;
use Filament\Forms;

class AssetRegister extends TableReport
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?int $navigationSort = 30;
    protected static bool $costCenterFilter = false;
    protected static ?string $reportPermission = Perm::ASSETS_MANAGE;

    public static function getNavigationGroup(): ?string { return __('Fixed assets'); }
    public static function getNavigationLabel(): string { return __('Asset register'); }
    public function getTitle(): string { return __('Asset register'); }

    public static function canAccess(): bool { return auth()->user()->can(Perm::ASSETS_MANAGE) || auth()->user()->can(Perm::REPORTS_VIEW); }

    public function mount(): void
    {
        $this->to ??= now()->toDateString();
        parent::mount();
    }

    protected function formState(): array { return ['to' => $this->to, 'branch' => $this->branch]; }

    protected function filterFields(): array
    {
        return [
            Forms\Components\DatePicker::make('to')->label(__('As of'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\Select::make('branch')->label(__('Branch'))->options(fn () => \App\Filament\Admin\Support\Options::branches())->placeholder(__('All branches'))->live(),
        ];
    }

    public function tables(): array
    {
        $d = $this->reports()->assetRegister($this->company(), \Illuminate\Support\Carbon::parse($this->to)->toDateString(), $this->branchId());
        $rows = array_map(fn ($r) => ['cells' => [
            ['t' => $r['asset']->code.' · '.$r['asset']->name_ar, 'url' => AssetResource::getUrl('view', ['record' => $r['asset']])],
            $r['asset']->category->name, $r['asset']->purchase_date->format('Y-m-d'), (string) $r['asset']->useful_life_months,
            $r['cost'], $r['period'], $r['accumulated'], $r['nbv'], __(Asset::STATUSES[$r['disposed'] ? 'disposed' : $r['asset']->status] ?? ''),
        ]], $d['rows']);
        $t = $d['totals'];
        if ($rows) {
            $rows[] = ['class' => 'total', 'cells' => [__('Total'), '', '', '', $t['cost'], $t['period'], $t['accumulated'], $t['nbv'], '']];
        }

        return [['headers' => [__('Asset'), __('Category'), __('Purchase date'), __('Life (months)'), __('Cost'), __('Depreciation this year'), __('Accumulated depreciation'), __('Net book value'), __('Status')],
            'numeric' => [3, 4, 5, 6, 7], 'rows' => $rows]];
    }
}
