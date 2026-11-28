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
use Filament\Notifications\Notification;
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
            $this->exportActions(),
            Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')->alpineClickHandler('window.print()'),
            $this->sendActions(),
        ];
    }

    /** Run an export in one language (ar or en): labels, titles and account / party names follow it. */
    public function inLanguage(string $lang, callable $fn): mixed
    {
        $before = app()->getLocale();
        app()->setLocale(\App\Support\InvoiceText::lang($lang));
        try {
            return $fn();
        } finally {
            app()->setLocale($before);
        }
    }

    /** Excel and PDF, each in Arabic or English. */
    protected function exportActions(): \Filament\Actions\ActionGroup
    {
        $actions = [];
        foreach (\App\Support\InvoiceText::LANGS as $lang => $name) {
            $actions[] = Action::make('pdf_'.$lang)->label(__('Download PDF').' — '.$name)->icon('heroicon-o-document-arrow-down')
                ->action(fn () => $this->inLanguage($lang, fn () => $this->downloadPdf()));
            $actions[] = Action::make('excel_'.$lang)->label(__('Export to Excel').' — '.$name)->icon('heroicon-o-table-cells')
                ->action(fn () => $this->inLanguage($lang, fn () => $this->downloadExcel()));
        }

        return \Filament\Actions\ActionGroup::make($actions)->label(__('Export'))->icon('heroicon-o-arrow-down-tray')->button()->color('gray');
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

    public function downloadPdf()
    {
        $pdf = $this->pdfBinary();

        return response()->streamDownload(fn () => print ($pdf), $this->exportFileName('pdf'), ['Content-Type' => 'application/pdf']);
    }

    protected function exportFileName(string $ext): string
    {
        return str($this->getTitle())->slug().'-'.substr((string) ($this->to ?? now()->toDateString()), 0, 10).'.'.$ext;
    }

    /** PDF built from the same rows as the Excel export (pure PHP, Arabic shaping and RTL). */
    /** Local path of the company logo for mPDF (it reads files directly), or null. */
    protected function logoFile(): ?string
    {
        $p = $this->company()->logo_path;
        $file = $p ? \Illuminate\Support\Facades\Storage::disk('public')->path($p) : null;

        return $file && is_file($file) ? $file : null;
    }

    public function pdfBinary(): string
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
            'company' => $this->company()->name, 'logo' => $this->logoFile(), 'trn' => $this->company()->trn, 'title' => strip_tags((string) $this->getTitle()), 'subtitle' => $this->exportSubtitle(),
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

        return $mpdf->Output('', 'S');
    }

    /** Email (PDF attached) or WhatsApp (link to the PDF) for any report. */
    protected function sendActions(): \Filament\Actions\ActionGroup
    {
        $title = fn () => strip_tags((string) $this->getTitle());
        $message = fn (string $lang = 'ar') => $this->inLanguage($lang, fn () => $title().' — '.$this->company()->name."\n".$this->exportSubtitle());
        $langField = fn () => Forms\Components\ToggleButtons::make('lang')->label(__('Report language'))->inline()->options(\App\Support\InvoiceText::LANGS)->default('ar')->required()->live()
            ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('message', $message((string) $state)));

        return \Filament\Actions\ActionGroup::make([
            Action::make('sendEmail')->label(__('Send by email'))->icon('heroicon-o-envelope')
                ->fillForm(fn () => ['lang' => 'ar', 'message' => $message(), 'excel' => false])
                ->form([
                    $langField(),
                    Forms\Components\TextInput::make('to')->label(__('To'))->email()->required(),
                    Forms\Components\TextInput::make('cc')->label(__('Cc'))->placeholder('a@x.com, b@y.com'),
                    Forms\Components\Textarea::make('message')->label(__('Message'))->rows(4)->required()->extraInputAttributes(['dir' => 'auto']),
                    Forms\Components\Toggle::make('excel')->label(__('Also attach the Excel file')),
                ])
                ->modalSubmitActionLabel(__('Send'))
                ->action(function (array $data) use ($title) {
                    try {
                        $files = $this->inLanguage($data['lang'] ?? 'ar', function () use ($data) {
                            $files = [$this->exportFileName('pdf') => [$this->pdfBinary(), 'application/pdf']];
                            if ($data['excel'] ?? false) {
                                ob_start();
                                $this->downloadExcel()->sendContent();
                                $files[$this->exportFileName('xlsx')] = [ob_get_clean(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                            }

                            return $files;
                        });
                        $mail = \Illuminate\Support\Facades\Mail::to($data['to']);
                        if (! empty($data['cc'])) {
                            $mail->cc(array_filter(array_map('trim', explode(',', $data['cc']))));
                        }
                        $mail->send(new \App\Mail\ReportMail($this->inLanguage($data['lang'] ?? 'ar', fn () => $title().' — '.$this->company()->name), $data['message'], $files));
                        \App\Models\AuditLog::record('emailed', null, null, ['report' => $title(), 'to' => $data['to']], null, $this->company()->id);
                        Notification::make()->success()->title(__('Report sent to :to', ['to' => $data['to']]))->send();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title(__('The email could not be sent'))->body(__('Check the mail settings (MAIL_*) in the .env file.').' '.$e->getMessage())->persistent()->send();
                    }
                }),
            Action::make('sendWhatsapp')->label(__('Send by WhatsApp'))->icon('heroicon-o-chat-bubble-left-right')
                ->fillForm(fn () => ['lang' => 'ar', 'message' => $message()])
                ->form([
                    $langField(),
                    Forms\Components\TextInput::make('phone')->label(__('WhatsApp number'))->tel()->helperText(__('Leave empty to choose the contact in WhatsApp.')),
                    Forms\Components\Textarea::make('message')->label(__('Message'))->rows(4)->required()->extraInputAttributes(['dir' => 'auto']),
                ])
                ->modalDescription(__('WhatsApp opens with the message and a link to the PDF (valid :d days). Press send there.', ['d' => self::SHARE_DAYS]))
                ->modalSubmitActionLabel(__('Open WhatsApp'))
                ->action(function (array $data) use ($title) {
                    $disk = \Illuminate\Support\Facades\Storage::disk('local');
                    foreach ($disk->files('shared-reports') as $old) { // links expire, so the files can go
                        if ($disk->lastModified($old) < now()->subDays(self::SHARE_DAYS + 1)->timestamp) {
                            $disk->delete($old);
                        }
                    }
                    $name = \Illuminate\Support\Str::uuid().'.pdf';
                    [$pdf, $fileName] = $this->inLanguage($data['lang'] ?? 'ar', fn () => [$this->pdfBinary(), $this->exportFileName('pdf')]);
                    \Illuminate\Support\Facades\Storage::disk('local')->put('shared-reports/'.$name, $pdf);
                    $link = \Illuminate\Support\Facades\URL::temporarySignedRoute('report.shared', now()->addDays(self::SHARE_DAYS), ['file' => $name, 'name' => $fileName]);
                    \App\Models\AuditLog::record('whatsapp', null, null, ['report' => $title(), 'phone' => $data['phone'] ?? null], null, $this->company()->id);
                    $url = 'https://wa.me/'.\App\Services\Accounting\InvoiceSharing::normalizePhone($data['phone'] ?? '').'?text='.rawurlencode($data['message']."\n".$link);
                    $this->js('window.open('.json_encode($url).', "_blank")');
                }),
        ])->label(__('Send'))->icon('heroicon-o-paper-airplane')->button()->color('success');
    }

    public const SHARE_DAYS = 30;

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
