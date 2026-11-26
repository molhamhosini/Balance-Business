<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Support\Options;
use App\Support\Perm;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Base for report pages: filters (period, branch), Excel export and print (PDF via the browser).
 * Every figure links down to the ledger or the entry behind it.
 */
abstract class ReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    #[Url] public ?string $from = null;
    #[Url] public ?string $to = null;
    #[Url] public ?string $branch = null;

    protected static ?string $reportPermission = Perm::REPORTS_VIEW;

    public static function getNavigationGroup(): ?string { return __('Reports'); }

    public static function canAccess(): bool
    {
        return auth()->user()->can(static::$reportPermission) || (static::$reportPermission === Perm::REPORTS_LIMITED && auth()->user()->can(Perm::REPORTS_VIEW));
    }

    public function mount(): void
    {
        $this->from ??= now()->startOfMonth()->toDateString();
        $this->to ??= now()->endOfMonth()->toDateString();
        $this->form->fill($this->formState());
    }

    protected function formState(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'branch' => $this->branch];
    }

    protected function filterFields(): array
    {
        return [
            Forms\Components\DatePicker::make('from')->label(__('From'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\DatePicker::make('to')->label(__('To'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\Select::make('branch')->label(__('Branch'))->options(fn () => Options::branches())->placeholder(__('All branches'))->live(),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([Forms\Components\Section::make()->columns(5)->schema($this->filterFields())])
            ->statePath(null);
    }

    protected function company() { return Filament::getTenant(); }

    protected function branchId(): ?int
    {
        $allowed = auth()->user()->allowedBranchIds($this->company()->id);
        $b = $this->branch ? (int) $this->branch : null;
        if ($allowed && (! $b || ! in_array($b, $allowed, true))) {
            return $allowed[0];
        }

        return $b;
    }

    /** [headers[], rows[][]] for Excel. Amounts in rows are fils (int) or strings. */
    abstract protected function exportRows(): array;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')->label(__('Export to Excel'))->icon('heroicon-o-table-cells')->color('gray')->action(fn () => $this->downloadExcel()),
            Action::make('print')->label(__('Print / PDF'))->icon('heroicon-o-printer')->color('gray')->alpineClickHandler('window.print()'),
        ];
    }

    public function downloadExcel()
    {
        [$headers, $rows] = $this->exportRows();
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setRightToLeft(app()->getLocale() === 'ar');
        $sheet->setTitle(mb_substr(strip_tags((string) $this->getTitle()), 0, 30));
        $sheet->fromArray([[$this->company()->name.' — '.$this->getTitle()], [__('From').' '.$this->from.' '.__('To').' '.$this->to], $headers], null, 'A1');
        $r = 4;
        foreach ($rows as $row) {
            $sheet->fromArray(array_map(fn ($v) => is_int($v) ? $v / 100 : $v, $row), null, 'A'.$r++);
        }
        $sheet->getStyle('A3:'.$sheet->getHighestColumn().'3')->getFont()->setBold(true);
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $file = str($this->getTitle())->slug().'-'.$this->from.'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $file);
    }
}
