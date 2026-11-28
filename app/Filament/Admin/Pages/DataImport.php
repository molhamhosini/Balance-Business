<?php

namespace App\Filament\Admin\Pages;

use App\Exceptions\AccountingException;
use App\Filament\Admin\Support\Options;
use App\Services\Accounting\DataImporter;
use App\Support\Perm;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Excel import of master data and opening balances, with a downloadable template per type. */
class DataImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?int $navigationSort = 90;
    protected static string $view = 'filament.pages.data-import';

    public ?array $data = [];
    public ?array $result = null;

    public static function getNavigationGroup(): ?string { return __('Configuration'); }
    public static function getNavigationLabel(): string { return __('Data import'); }
    public function getTitle(): string { return __('Data import from Excel'); }

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u->can(Perm::PARTIES_MANAGE) && ($u->can(Perm::INVENTORY_MANAGE) || $u->can(Perm::ASSETS_MANAGE));
    }

    public function mount(): void
    {
        $this->form->fill(['type' => request('type', 'customers'), 'date' => now()->toDateString(), 'post' => true]);
    }

    public function form(Form $form): Form
    {
        $opening = fn (Get $get) => in_array($get('type'), ['open_invoices', 'opening_stock'], true);

        return $form->statePath('data')->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\Select::make('type')->label(__('What to import'))->required()->live()
                    ->options(fn () => collect(DataImporter::TYPES)->map(fn ($l) => __($l))->all()),
                Forms\Components\Select::make('counter_account_id')->label(__('Opening balance account'))->visible($opening)->required($opening)->searchable()
                    ->options(fn () => Options::postableAccounts(fn ($q) => $q->where('class', 'equity')))
                    ->helperText(__('Usually an "opening balances" equity account; it is cleared when all opening balances are in.')),
                Forms\Components\Toggle::make('post')->label(__('Confirm (post) the invoices after import'))->default(true)->inline(false)
                    ->visible(fn (Get $get) => in_array($get('type'), ['sales_invoices', 'purchase_invoices'], true))
                    ->helperText(__('Off: they are imported as drafts to review and confirm yourself.')),
                Forms\Components\DatePicker::make('date')->label(__('Opening stock date'))->visible(fn (Get $get) => $get('type') === 'opening_stock')->native(false)->displayFormat('Y-m-d'),
                Forms\Components\FileUpload::make('file')->label(__('Excel file'))->disk('local')->directory('imports')->required()->columnSpanFull()
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv']),
            ]),
        ]);
    }

    public function columns(): array
    {
        return DataImporter::columns($this->data['type'] ?? 'customers');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')->label(__('Download template'))->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(function () {
                    $type = $this->data['type'] ?? 'customers';
                    $book = app(DataImporter::class)->template($type);

                    return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'template-'.$type.'.xlsx');
                }),
        ];
    }

    public function import(): void
    {
        $d = $this->form->getState();
        $svc = app(DataImporter::class);
        try {
            $rows = $svc->readRows(Storage::disk('local')->path($d['file']), $d['type']);
            $this->result = $svc->import(Filament::getTenant(), $d['type'], $rows, auth()->user(), ['counter_account_id' => $d['counter_account_id'] ?? null, 'date' => $d['date'] ?? null, 'post' => (bool) ($d['post'] ?? true)]) + ['rows' => count($rows), 'type' => $d['type']];
            $n = Notification::make()->title(__('Import finished'))->body(__(':c added, :u updated', ['c' => $this->result['created'], 'u' => $this->result['updated']]));
            ($this->result['errors'] ? $n->warning() : $n->success())->send();
        } catch (AccountingException $e) {
            Notification::make()->danger()->title(__('Rejected'))->body($e->getMessage())->persistent()->send();
        }
    }
}
