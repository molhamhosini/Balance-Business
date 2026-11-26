<?php

namespace App\Filament\Admin\Pages;

use App\Support\Perm;
use Filament\Forms;
use Livewire\Attributes\Url;

class SlowMoving extends TableReport
{
    protected static ?string $navigationIcon = 'heroicon-o-pause-circle';
    protected static ?int $navigationSort = 22;
    protected static bool $costCenterFilter = false;
    protected static ?string $reportPermission = Perm::INVENTORY_VIEW;

    #[Url] public ?string $days = '60';

    public static function getNavigationGroup(): ?string { return __('Inventory'); }
    public static function getNavigationLabel(): string { return __('Slow-moving items'); }
    public function getTitle(): string { return __('Slow-moving items'); }

    public function mount(): void
    {
        $this->to ??= now()->toDateString();
        parent::mount();
    }

    protected function formState(): array { return ['to' => $this->to, 'days' => $this->days]; }

    protected function filterFields(): array
    {
        return [
            Forms\Components\DatePicker::make('to')->label(__('As of'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\TextInput::make('days')->label(__('No sale or issue for (days)'))->numeric()->minValue(1)->live(debounce: 500),
        ];
    }

    public function tables(): array
    {
        $rows = array_map(fn ($r) => ['cells' => [
            ['t' => $r['code'].' · '.$r['name'], 'url' => ItemCard::getUrl(['item' => $r['item_id']])], $r['unit'], ['q' => $r['qty']], $r['value'],
            $r['last_out'] ?? __('Never'), ['t' => $r['idle_days'] === null ? '—' : (string) $r['idle_days'], 'n' => true],
        ]], $this->reports()->slowMoving($this->company(), \Illuminate\Support\Carbon::parse($this->to)->toDateString(), max(1, (int) $this->days)));

        return [['headers' => [__('Item'), __('Unit'), __('Quantity'), __('Value'), __('Last sale / issue'), __('Idle days')], 'numeric' => [2, 3, 5], 'rows' => $rows,
            'empty' => __('No slow-moving items.')]];
    }
}
