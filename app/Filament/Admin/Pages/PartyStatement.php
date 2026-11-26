<?php

namespace App\Filament\Admin\Pages;

use App\Models\Party;
use App\Services\Accounting\ReportService;
use App\Support\Perm;
use Filament\Forms;
use Livewire\Attributes\Url;

class PartyStatement extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';
    protected static ?int $navigationSort = 7;
    protected static string $view = 'filament.pages.party-statement';
    protected static ?string $reportPermission = Perm::INVOICES_VIEW;

    #[Url] public ?string $party = null;

    public static function getNavigationLabel(): string { return __('Customer / supplier statement'); }
    public function getTitle(): string { return __('Customer / supplier statement'); }

    public static function canAccess(): bool { return auth()->user()->can(Perm::INVOICES_VIEW); }

    public function mount(): void
    {
        $this->from ??= now()->startOfYear()->toDateString();
        parent::mount();
    }

    protected function formState(): array { return parent::formState() + ['party' => $this->party]; }

    protected function filterFields(): array
    {
        return [
            Forms\Components\Select::make('party')->label(__('Customer / supplier'))->searchable()->live()->columnSpan(3)
                ->options(fn () => Party::where('company_id', $this->company()->id)->orderBy('type')->orderBy('code')->get()
                    ->mapWithKeys(fn ($p) => [$p->id => __(Party::TYPES[$p->type]).' · '.$p->label])->all()),
            Forms\Components\DatePicker::make('from')->label(__('From'))->live()->native(false)->displayFormat('Y-m-d'),
            Forms\Components\DatePicker::make('to')->label(__('To'))->live()->native(false)->displayFormat('Y-m-d'),
        ];
    }

    public function getParty(): ?Party
    {
        return $this->party ? Party::where('company_id', $this->company()->id)->find($this->party) : null;
    }

    public function getData(): ?array
    {
        $p = $this->getParty();

        return $p ? app(ReportService::class)->partyStatement($p, $this->from, $this->to) : null;
    }

    protected function exportRows(): array
    {
        $d = $this->getData();
        $rows = [['', '', __('Opening balance'), '', '', $d['opening'] ?? 0]];
        foreach ($d['rows'] ?? [] as $r) {
            $rows[] = [$r['date'], $r['number'], $r['description'], $r['debit'], $r['credit'], $r['balance']];
        }
        $rows[] = ['', '', __('Total'), $d['debit'] ?? 0, $d['credit'] ?? 0, $d['closing'] ?? 0];

        return [[__('Date'), __('Number'), __('Description'), __('Debit'), __('Credit'), __('Balance')], $rows];
    }
}
