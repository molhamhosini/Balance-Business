<?php

namespace App\Filament\Admin\Resources\StockDocumentResource\Pages;

use App\Filament\Admin\Resources\StockDocumentResource;
use App\Models\StockDocument;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockDocuments extends ListRecords
{
    protected static string $resource = StockDocumentResource::class;

    protected function getHeaderActions(): array
    {
        $tenant = \Filament\Facades\Filament::getTenant();

        return [
            Actions\Action::make('closing')->label(__('Closing stock adjustment'))->icon('heroicon-o-calculator')->color('warning')
                ->visible(fn () => $tenant->isPeriodic() && auth()->user()->can(\App\Support\Perm::INVENTORY_POST))
                ->form([
                    \Filament\Forms\Components\DatePicker::make('date')->label(__('Period end date'))->required()->native(false)->displayFormat('Y-m-d')->default(now()->endOfMonth()),
                    \Filament\Forms\Components\Select::make('branch_id')->label(__('Branch'))->options(fn () => \App\Filament\Admin\Support\Options::branches())->default(fn () => array_key_first(\App\Filament\Admin\Support\Options::branches()))->required(),
                ])
                ->modalDescription(__('Sets the inventory account to the value of the stock on hand at that date (quantities × weighted average cost) against cost of sales. Record the physical count first.'))
                ->action(function (array $data) use ($tenant) {
                    try {
                        $d = app(\App\Services\Accounting\InventoryService::class)->postClosing($tenant, \Illuminate\Support\Carbon::parse($data['date'])->toDateString(), (int) $data['branch_id'], auth()->user());
                        \Filament\Notifications\Notification::make()->success()->title(__('Closing stock adjustment posted'))->body($d->journal_entry_id ? $d->number : __('No difference: the inventory account already equals the stock value.'))->send();
                        $this->redirect(StockDocumentResource::getUrl('view', ['record' => $d]));
                    } catch (\App\Exceptions\AccountingException $e) {
                        \Filament\Notifications\Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Actions\ActionGroup::make(collect(StockDocument::TYPES)->except('closing')->map(fn ($l, $k) => Actions\Action::make('new_'.$k)->label(__($l))
            ->url(StockDocumentResource::getUrl('create', ['type' => $k])))->values()->all())
            ->label(__('New document'))->icon('heroicon-o-plus')->button()->visible(StockDocumentResource::canCreate()),
        ];
    }
}
