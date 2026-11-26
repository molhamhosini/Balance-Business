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
    #[Url] public ?string $cc = null;

    /** Show the cost center filter (reports built on journal lines). */
    protected static bool $costCenterFilter = true;

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
        return ['from' => $this->from, 'to' => $this->to, 'branch' => $this->branch, 'cc' => $this->cc];
    }

    protected function filterFields(): array
    {
        return [
            Forms\Components\DatePicker::make('from')->label(__('From'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\DatePicker::make('to')->label(__('To'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\Select::make('branch')->label(__('Branch'))->options(fn () => Options::branches())->placeholder(__('All branches'))->live(),
            ...(static::$costCenterFilter ? [$this->costCenterField()] : []),
        ];
    }

    protected function costCenterField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('cc')->label(__('Cost center'))->options(fn () => Options::costCenters())->placeholder(__('All cost centers'))->searchable()->live();
    }

    /** Report service with the page's cost center filter applied. */
    protected function reports(): \App\Services\Accounting\ReportService
    {
        return app(\App\Services\Accounting\ReportService::class)->forCostCenter(static::$costCenterFilter && $this->cc ? (int) $this->cc : null);
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
            Action::make('pdf')->label(__('Download PDF'))->icon('heroicon-o-document-arrow-down')->color('gray')->action(fn () => $this->downloadPdf()),
            Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')->alpineClickHandler('window.print()'),
        ];
    }

    /** Period / date line under the title. */
    protected function exportSubtitle(): string
    {
        $parts = [];
        if ($this->from && array_key_exists('from', $this->formState())) {
            $parts[] = __('From').' '.substr((string) $this->from, 0, 10).' '.__('To').' '.substr((string) $this->to, 0, 10);
        } elseif ($this->to) {
            $parts[] = __('As of').' '.substr((string) $this->to, 0, 10);
        }
        if ($this->branch) {
            $parts[] = __('Branch').': '.\App\Models\Branch::find($this->branch)?->name;
        }
        if ($this->cc) {
            $parts[] = __('Cost center').': '.\App\Models\CostCenter::find($this->cc)?->label;
        }

        return implode(' · ', $parts);
    }

    /** PDF built from the same rows as the Excel export (pure PHP, Arabic shaping and RTL). */
    public function downloadPdf()
    {
        [$headers, $rows] = $this->exportRows();
        $numericCol = [];
        $out = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach (array_values($row) as $i => $v) {
                $isNum = is_int($v) || is_float($v);
                if ($isNum) {
                    $numericCol[$i] = true;
                }
                $cells[] = ['t' => is_int($v) ? ($v === 0 ? '' : \App\Support\Money::format($v)) : (is_float($v) ? number_format($v, 3) : (string) $v), 'n' => $isNum];
            }
            $first = (string) ($row[array_key_first($row) ?? 0] ?? '');
            $bold = count($row) === 1 || str_starts_with(trim($first), __('Total')) || str_starts_with(trim($first), 'Total');
            $out[] = ['cells' => $cells ?: [['t' => ' ', 'n' => false]], 'bold' => $bold];
        }
        $html = view('pdf.report', [
            'company' => $this->company()->name, 'title' => strip_tags((string) $this->getTitle()), 'subtitle' => $this->exportSubtitle(),
            'headers' => $headers, 'rows' => $out, 'numeric' => array_keys($numericCol),
        ])->render();

        @mkdir(storage_path('framework/cache/mpdf'), 0775, true);
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => count($headers) > 6 ? 'A4-L' : 'A4', 'tempDir' => storage_path('framework/cache/mpdf'),
            'margin_top' => 10, 'margin_bottom' => 12, 'margin_left' => 8, 'margin_right' => 8]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->SetDirectionality(app()->getLocale() === 'ar' ? 'rtl' : 'ltr');
        $mpdf->setFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML($html);
        $file = str($this->getTitle())->slug().'-'.substr((string) ($this->to ?? now()->toDateString()), 0, 10).'.pdf';

        return response()->streamDownload(fn () => print ($mpdf->Output('', 'S')), $file, ['Content-Type' => 'application/pdf']);
    }

    public function downloadExcel()
    {
        [$headers, $rows] = $this->exportRows();
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setRightToLeft(app()->getLocale() === 'ar');
        $sheet->setTitle(mb_substr(trim(str_replace(['\\', '/', '?', '*', ':', '[', ']'], '-', strip_tags((string) $this->getTitle())), " '"), 0, 30));
        $sub = $this->exportSubtitle();
        $sheet->fromArray([[$this->company()->name.' — '.$this->getTitle()], [$sub], $headers], null, 'A1');
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
