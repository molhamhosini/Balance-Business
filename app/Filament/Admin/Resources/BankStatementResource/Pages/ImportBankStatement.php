<?php

namespace App\Filament\Admin\Resources\BankStatementResource\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Resources\BankStatementResource;
use App\Filament\Admin\Support\Options;
use App\Models\Account;
use App\Models\BankStatement;
use App\Services\Accounting\BankingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ImportBankStatement extends CreateRecord
{
    protected static string $resource = BankStatementResource::class;

    public function getTitle(): string { return __('Import bank statement'); }

    public function form(Form $form): Form
    {
        return $form->schema([Forms\Components\Section::make()->columns(3)->schema([
            Forms\Components\Select::make('account_id')->label(__('Bank account'))->required()->searchable()->live()
                ->options(fn () => Options::postableAccounts(fn ($q) => $q->where('is_bank', true)))
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    $last = $state ? BankStatement::where('account_id', $state)->latest('date_to')->first() : null;
                    if ($last) {
                        $set('opening_balance', $last->closing_balance);
                        $set('date_from', $last->date_to->addDay()->toDateString());
                    }
                }),
            Forms\Components\DatePicker::make('date_from')->label(__('From'))->required()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\DatePicker::make('date_to')->label(__('To'))->required()->native(false)->displayFormat('Y-m-d')->afterOrEqual('date_from'),
            Forms\Components\TextInput::make('opening_balance')->label(__('Statement opening balance'))->numeric()->required()->default(0),
            Forms\Components\TextInput::make('closing_balance')->label(__('Statement closing balance'))->numeric()->required(),
            Forms\Components\FileUpload::make('file')->label(__('Statement file (Excel or CSV)'))->disk('local')->directory('imports')->required()->storeFileNamesIn('file_name')
                ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv', 'text/plain', 'application/csv'])
                ->helperText(__('Columns are found by their header: date, description, reference, and amount (or debit / credit). Arabic or English headers.')),
        ])]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $svc = app(BankingService::class);
            $rows = $svc->readStatementFile(Storage::disk('local')->path($data['file']));
            $rows = array_values(array_filter($rows, fn ($r) => $r['date'] >= $data['date_from'] && $r['date'] <= $data['date_to']));
            if (! $rows) {
                throw new AccountingException(__('The file has no lines within the selected dates.'));
            }
            $st = $svc->importStatement(Account::findOrFail($data['account_id']), $rows, [
                'date_from' => $data['date_from'], 'date_to' => $data['date_to'],
                'opening_balance' => $data['opening_balance'], 'closing_balance' => $data['closing_balance'],
            ], auth()->user(), $data['file_name'] ?? basename($data['file']));
            Notification::make()->success()->title(__('Statement imported'))->body(__(':n lines, :m matched automatically.', ['n' => count($rows), 'm' => $st->lines()->whereNotNull('journal_line_id')->count()]))->send();

            return $st;
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->persistent()->send();
            throw new Halt;
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            Notification::make()->danger()->title(__('Rejected'))->body(__('The file could not be read.'))->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string { return static::getResource()::getUrl('view', ['record' => $this->record]); }
}
