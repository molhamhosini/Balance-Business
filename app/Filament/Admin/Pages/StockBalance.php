<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Support\Options;
use App\Support\Perm;
use Filament\Forms;
use Livewire\Attributes\Url;

class StockBalance extends TableReport
{
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?int $navigationSort = 20;
    protected static bool $costCenterFilter = false;
    protected static ?string $reportPermission = Perm::INVENTORY_VIEW;

    #[Url] public ?string $warehouse = null;

    public static function getNavigationGroup(): ?string { return __('Inventory'); }
    public static function getNavigationLabel(): string { return __('Stock balances'); }
    public function getTitle(): string { return __('Stock balances'); }

    public function mount(): void
    {
        $this->to ??= now()->toDateString();
        parent::mount();
    }

    protected function formState(): array { return ['to' => $this->to, 'warehouse' => $this->warehouse]; }

    protected function filterFields(): array
    {
        return [
            Forms\Components\DatePicker::make('to')->label(__('As of'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\Select::make('warehouse')->label(__('Warehouse'))->options(fn () => Options::warehouses())->placeholder(__('All warehouses'))->live(),
        ];
    }

    public function tables(): array
    {
        $d = $this->reports()->stockBalances($this->company(), \Illuminate\Support\Carbon::parse($this->to)->toDateString(), $this->warehouse ? (int) $this->warehouse : null);
        $rows = array_map(fn ($r) => ['cells' => [
            ['t' => $r['code'].' · '.$r['name'], 'url' => ItemCard::getUrl(['item' => $r['item_id'], 'to' => $this->to, 'warehouse' => $this->warehouse])],
            $r['unit'], ['q' => $r['qty'], 'cls' => $r['qty'] < 0 ? 'bb-bad' : ''], ['t' => number_format($r['avg'] / 100, 4), 'n' => true], $r['value'], $r['last_move'],
        ]], $d['rows']);
        if ($rows) {
            $rows[] = ['class' => 'total', 'cells' => [__('Total'), '', '', '', $d['total'], '']];
        }

        return [['description' => __('Quantities from the stock ledger, valued at weighted average cost.'),
            'headers' => [__('Item'), __('Unit'), __('Quantity'), __('Average cost'), __('Value'), __('Last movement')], 'numeric' => [2, 3, 4], 'rows' => $rows]];
    }
}
