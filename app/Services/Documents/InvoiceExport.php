<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** PDF of one invoice, and PDF / Excel of an invoice list (same layout as the report exports). */
class InvoiceExport
{
    public static function logoFile(Company $c): ?string
    {
        $file = $c->logo_path ? Storage::disk('public')->path($c->logo_path) : null;

        return $file && is_file($file) ? $file : null;
    }

    protected static function mpdf(string $format): \Mpdf\Mpdf
    {
        @mkdir(storage_path('framework/cache/mpdf'), 0775, true);
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => $format, 'tempDir' => storage_path('framework/cache/mpdf'),
            'margin_top' => 10, 'margin_bottom' => 12, 'margin_left' => 10, 'margin_right' => 10]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->SetDirectionality('rtl');
        $mpdf->setFooter('{PAGENO} / {nbpg}');

        return $mpdf;
    }

    public static function invoicePdf(Invoice $inv): string
    {
        $inv->loadMissing(['company', 'branch', 'party', 'lines.taxCode', 'original']);
        $mpdf = static::mpdf('A4');
        $mpdf->SetTitle($inv->display_number);
        $mpdf->WriteHTML(view('pdf.invoice', ['inv' => $inv, 'logo' => static::logoFile($inv->company)])->render());

        return $mpdf->Output('', 'S');
    }

    public static function fileName(Invoice $inv, string $ext = 'pdf'): string
    {
        return str_replace(['/', '\\', ' '], '-', $inv->display_number).'.'.$ext;
    }

    /** @return array{0: array<int,string>, 1: array<int,array<int,mixed>>} money as int fils, totals row last */
    public static function listRows(Collection $invoices, callable $kindLabel, array $statusLabels, string $partyLabel): array
    {
        $headers = [__('Number'), __('Type'), $partyLabel, __('Date'), __('Due date'), __('Untaxed amount'), __('VAT'), __('Total'), __('Amount due'), __('Status')];
        $rows = [];
        $t = [0, 0, 0, 0];
        foreach ($invoices as $inv) {
            $sign = str_ends_with($inv->kind, '_return') ? -1 : 1;
            $vals = [Money::toCents($inv->subtotal), Money::toCents($inv->tax_total), Money::toCents($inv->total), $inv->openCents()];
            if (! in_array($inv->status, ['draft', 'cancelled'], true) && ! $inv->isQuotation()) {
                foreach ($vals as $i => $v) {
                    $t[$i] += $sign * $v;
                }
            }
            $state = $inv->status === 'posted' ? $inv->paymentState() : $inv->status;
            $rows[] = [$inv->display_number, $kindLabel($inv->kind), (string) $inv->party?->name_ar, $inv->date?->format('Y-m-d'), (string) $inv->due_date?->format('Y-m-d'),
                ...array_map(fn ($v) => $sign * $v, $vals), $statusLabels[$state] ?? $state];
        }
        $rows[] = [__('Total').' ('.count($invoices).')', '', '', '', '', ...$t, ''];

        return [$headers, $rows];
    }

    public static function listPdf(Company $c, string $title, string $subtitle, array $headers, array $rows): string
    {
        $numeric = [];
        $out = [];
        foreach ($rows as $k => $row) {
            $cells = [];
            foreach (array_values($row) as $i => $v) {
                if (is_int($v)) {
                    $numeric[$i] = true;
                }
                $cells[] = ['t' => is_int($v) ? Money::format($v) : (string) $v, 'n' => is_int($v)];
            }
            $out[] = ['cells' => $cells, 'bold' => $k === array_key_last($rows)];
        }
        $html = view('pdf.report', ['company' => $c->name, 'logo' => static::logoFile($c), 'trn' => $c->trn, 'title' => $title, 'subtitle' => $subtitle,
            'headers' => $headers, 'rows' => $out, 'numeric' => array_keys($numeric)])->render();
        $mpdf = static::mpdf('A4-L');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public static function listExcel(Company $c, string $title, string $subtitle, array $headers, array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setRightToLeft(app()->getLocale() === 'ar');
        $sheet->setTitle(mb_substr(trim(str_replace(['\\', '/', '?', '*', ':', '[', ']'], '-', $title), " '"), 0, 30));
        $sheet->fromArray([[$c->name.' — '.$title], [$subtitle], $headers], null, 'A1');
        $r = 4;
        foreach ($rows as $row) {
            $sheet->fromArray(array_map(fn ($v) => is_int($v) ? $v / 100 : $v, $row), null, 'A'.$r++);
        }
        $last = $r - 1;
        $sheet->getStyle('A3:J3')->getFont()->setBold(true);
        $sheet->getStyle("A{$last}:J{$last}")->getFont()->setBold(true);
        $sheet->getStyle("F4:I{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        ob_start();
        (new Xlsx($book))->save('php://output');

        return ob_get_clean();
    }
}
