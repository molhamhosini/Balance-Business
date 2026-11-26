<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\SalesInvoiceResource;
use App\Models\InvoiceLine;
use App\Models\Party;
use App\Services\Accounting\InventoryService;
use App\Support\Money;
use App\Support\Perm;
use Filament\Forms;
use Livewire\Attributes\Url;

/**
 * Item movement by customer: posted sales invoices and returns, filtered by customer and item.
 * No customer / no item chosen means all customers / all items.
 */
class ItemSalesByCustomer extends TableReport
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?int $navigationSort = 23;
    protected static bool $costCenterFilter = false;
    protected static ?string $reportPermission = Perm::INVOICES_VIEW;

    #[Url] public ?string $customer = null;
    #[Url] public ?string $item = null;

    public static function getNavigationGroup(): ?string { return __('Inventory'); }
    public static function getNavigationLabel(): string { return __('Item movement by customer'); }
    public function getTitle(): string { return __('Item movement by customer'); }

    public function mount(): void
    {
        $this->from ??= now()->startOfYear()->toDateString();
        $this->to ??= now()->toDateString();
        parent::mount();
    }

    protected function formState(): array { return parent::formState() + ['customer' => $this->customer, 'item' => $this->item]; }

    protected function filterFields(): array
    {
        return array_merge([
            Forms\Components\Select::make('customer')->label(__('Customer'))->searchable()->live()->placeholder(__('All customers'))
                ->options(fn () => Party::where('company_id', $this->company()->id)->where('type', 'customer')->orderBy('code')->get()->mapWithKeys(fn ($p) => [$p->id => $p->label])->all()),
            Forms\Components\Select::make('item')->label(__('Item'))->searchable()->live()->placeholder(__('All items'))
                ->options(fn () => \App\Filament\Admin\Support\Options::items()),
        ], parent::filterFields());
    }

    /** @return \Illuminate\Support\Collection<int, InvoiceLine> */
    public function lines()
    {
        return InvoiceLine::query()->with(['invoice.party', 'item'])->whereNotNull('item_id')
            ->whereHas('invoice', fn ($q) => $q->where('company_id', $this->company()->id)->where('status', 'posted')->whereIn('kind', ['sale', 'sale_return'])
                ->when($this->from, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                ->when($this->to, fn ($q, $d) => $q->whereDate('date', '<=', $d))
                ->when($this->branch, fn ($q, $b) => $q->where('branch_id', $b))
                ->when($this->customer, fn ($q, $c) => $q->where('party_id', $c)))
            ->when($this->item, fn ($q, $i) => $q->where('item_id', $i))
            ->get()->sortBy(fn ($l) => [$l->invoice->date->toDateString(), $l->invoice->number, $l->sort])->values();
    }

    public function tables(): array
    {
        $detail = [];
        $summary = [];
        $totQty = $totNet = 0;
        foreach ($this->lines() as $l) {
            $inv = $l->invoice;
            $sign = $inv->kind === 'sale_return' ? -1 : 1;
            $baseQty = $sign * InventoryService::milli(round((float) $l->quantity * (float) ($l->unit_factor ?: 1), 3));
            $net = $sign * (int) round(Money::toCents($l->net) * (float) ($inv->fx_rate ?: 1));
            $detail[] = ['cells' => [
                $inv->date->toDateString(),
                ['t' => $inv->number, 'url' => SalesInvoiceResource::getUrl('view', ['record' => $inv])],
                __(\App\Models\Invoice::KINDS[$inv->kind]),
                $inv->party?->label ?? '',
                $l->item->label,
                ['q' => $sign * InventoryService::milli((string) $l->quantity)], $l->unit ?: $l->item->unit,
                (int) round(Money::toCents($l->unit_price) * (float) ($inv->fx_rate ?: 1)), $net,
            ]];
            $k = $inv->party_id.'-'.$l->item_id;
            $summary[$k] ??= ['party' => $inv->party?->label ?? '', 'item' => $l->item->label, 'unit' => $l->item->unit, 'qty' => 0, 'net' => 0, 'count' => 0];
            $summary[$k]['qty'] += $baseQty;
            $summary[$k]['net'] += $net;
            $summary[$k]['count']++;
            $totQty += $baseQty;
            $totNet += $net;
        }
        usort($summary, fn ($a, $b) => [$a['party'], $a['item']] <=> [$b['party'], $b['item']]);
        $sumRows = array_map(fn ($s) => ['cells' => [$s['party'], $s['item'], ['t' => (string) $s['count'], 'n' => true], ['q' => $s['qty']], $s['unit'],
            $s['qty'] ? (int) round($s['net'] * 1000 / $s['qty']) : 0, $s['net']]], $summary);
        if ($sumRows) {
            $sumRows[] = ['cells' => [__('Total'), '', '', ['q' => $totQty], '', '', $totNet], 'class' => 'total'];
        }

        return [
            ['title' => __('Summary by customer and item'), 'headers' => [__('Customer'), __('Item'), __('Invoice count'), __('Quantity'), __('Unit'), __('Average price'), __('Net amount')],
                'rows' => $sumRows, 'empty' => __('No sales in this period.')],
            ['title' => __('Details'), 'headers' => [__('Date'), __('Number'), __('Document'), __('Customer'), __('Item'), __('Quantity'), __('Unit'), __('Unit price'), __('Net amount')],
                'rows' => $detail, 'empty' => __('No sales in this period.')],
        ];
    }
}
