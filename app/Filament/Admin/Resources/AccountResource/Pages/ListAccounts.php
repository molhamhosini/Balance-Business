<?php

namespace App\Filament\Admin\Resources\AccountResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\AccountResource;
use App\Services\Accounting\ChartImporter;
use App\Support\Perm;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('New account')),
            Actions\Action::make('import')->label(__('Import from Excel'))->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->visible(fn () => auth()->user()->can(Perm::ACCOUNTS_MANAGE))
                ->modalDescription(__('Same layout as the supplied chart-of-accounts workbook. Existing codes are updated, new codes are added, nothing is deleted.'))
                ->form([Forms\Components\FileUpload::make('file')->label(__('Excel file'))->disk('local')->directory('imports')->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])->required()])
                ->action(function (array $data) {
                    try {
                        $imp = app(ChartImporter::class);
                        $r = $imp->import(Filament::getTenant(), $imp->readRows(Storage::disk('local')->path($data['file'])));
                        Notification::make()->success()->title(__('Import finished'))->body(__(':c added, :u updated', ['c' => $r['created'], 'u' => $r['updated']]))->send();
                    } catch (AccountingException $e) {
                        Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
