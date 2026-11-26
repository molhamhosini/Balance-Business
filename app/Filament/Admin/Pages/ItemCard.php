<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Support\Options;
use App\Models\Item;
use App\Support\Perm;
use Filament\Forms;
use Livewire\Attributes\Url;

class ItemCard extends TableReport
{
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';
    protected static ?int $navigationSort = 21;
    protected static bool $costCenterFilter = false;
    protected static ?string $reportPermission = Perm::INVENTORY_VIEW;

    #[Url] public ?string $item = null;
    #[Url] public ?string $warehouse = null;

    public static function getNavigationGroup(): ?string { return __('Inventory'); }
    public static function getNavigationLabel(): string { return __('Item card'); }
    public function getTitle(): string { return __('Item card'); }

    public function mount(): void
    {
        $this->from ??= now()->startOfYear()->toDateString();
        parent::mount();
    }

    protected function formState(): array { return ['from' => $this->from, 'to' => $this->to, 'item' => $this->item, 'warehouse' => $this->warehouse]; }

    protected function filterFields(): array
    {
        return [
            Forms\Components\Select::make('item')->label(__('Item'))->options(fn () => Options::items('stock'))->searchable()->live()->columnSpan(2),
            Forms\Components\DatePicker::make('from')->label(__('From'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\DatePicker::make('to')->label(__('To'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\Select::make('warehouse')->label(__('Warehouse'))->options(fn () => Options::warehouses())->placeholder(__('All warehouses'))->live(),
        ];
    }

    public function tables(): array
    {
        $item = $this->item ? Item::where('company_id', $this->company()->id)->find($this->item) : null;
        if (! $item) {
            return [['headers' => [__('Item')], 'rows' => [], 'empty' => __('Choose an item.')]];
        }
        $d = $this->reports()->itemCard($item, \Illuminate\Support\Carbon::parse($this->from)->toDateString(), \Illuminate\Support\Carbon::parse($this->to)->toDateString(), $this->warehouse ? (int) $this->warehouse : null);
        $rows = [['class' => 'head', 'cells' => [$this->from, __('Opening balance'), '', '', '', '', '', ['q' => $d['open_qty']], $d['open_value'], '']]];
        foreach ($d['rows'] as $r) {
            $rows[] = ['cells' => [
                $r['date'], $r['description'] ?? '', $r['warehouse'],
                $r['entry'] ? ['t' => $r['entry']->number, 'url' => \App\Filament\Admin\Resources\JournalEntryResource::getUrl('view', ['record' => $r['entry']])] : '',
                ['q' => $r['in'] ?: null], ['q' => $r['out'] ?: null], $r['value'], ['q' => $r['qty']], $r['balance_value'], ['t' => number_format($r['avg'], 4), 'n' => true],
            ]];
        }
        $rows[] = ['class' => 'total', 'cells' => [$this->to, __('Closing balance'), '', '', '', '', '', ['q' => $d['qty']], $d['value'], '']];

        return [['title' => $item->label.' ('.$item->unit.')',
            'headers' => [__('Date'), __('Description'), __('Warehouse'), __('Entry'), __('In'), __('Out'), __('Value'), __('Balance qty'), __('Balance value'), __('Average cost')],
            'numeric' => [4, 5, 6, 7, 8, 9], 'rows' => $rows]];
    }
}
