<?php

namespace App\Filament\Admin\Pages;

use App\Services\Accounting\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** VAT 201 figures for a tax period, laid out like the client's template. */
class VatReturn extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?int $navigationSort = 6;
    protected static bool $costCenterFilter = false;
    protected static string $view = 'filament.pages.vat-return';

    public static function getNavigationLabel(): string { return __('VAT return (VAT 201)'); }
    public function getTitle(): string { return __('VAT return (VAT 201)'); }

    public function mount(): void
    {
        $this->from ??= now()->firstOfQuarter()->toDateString();
        $this->to ??= now()->lastOfQuarter()->toDateString();
        parent::mount();
    }

    protected function filterFields(): array
    {
        return array_slice(parent::filterFields(), 0, 2); // VAT is filed per company, not per branch
    }

    public function getData(): array
    {
        return $this->reports()->vat201($this->company(), $this->from, $this->to);
    }

    protected function exportRows(): array
    {
        $rows = [];
        foreach (ReportService::VAT201_LABELS as $box => $label) {
            $b = $this->getData()[$box];
            $rows[] = [$box, $label, $b['amount'], $b['vat'], $b['adjustment']];
        }

        return [[__('Box'), __('Description'), __('Amount (AED)'), __('VAT amount (AED)'), __('Adjustment (AED)')], $rows];
    }

    /** Fill the client's own VAT 201 workbook (columns L, M, N) and download it. */
    public function downloadExcel()
    {
        $book = IOFactory::load(database_path('data/vat201-template.xlsx'));
        $sheet = $book->getActiveSheet();
        $data = $this->getData();
        foreach ($sheet->getRowIterator() as $row) {
            $r = $row->getRowIndex();
            $box = trim((string) $sheet->getCell('B'.$r)->getValue());
            if (isset($data[$box]) && $box !== '' && $box !== 'الرمز') {
                $b = $data[$box];
                if (! in_array($box, ['12', '13', '14'], true)) {
                    $sheet->setCellValue('L'.$r, $b['amount'] / 100);
                }
                $sheet->setCellValue('M'.$r, $b['vat'] / 100);
                $sheet->setCellValue('N'.$r, $b['adjustment'] / 100);
            }
        }

        return response()->streamDownload(fn () => IOFactory::createWriter($book, 'Xlsx')->save('php://output'), 'VAT201-'.$this->from.'-'.$this->to.'.xlsx');
    }
}
