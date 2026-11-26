<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Support\Options;
use App\Models\Account;
use App\Services\Accounting\ReportService;
use App\Support\Perm;
use Filament\Forms;
use Livewire\Attributes\Url;

class Ledger extends ReportPage
{
    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.pages.ledger';
    protected static ?string $reportPermission = Perm::REPORTS_LIMITED;

    #[Url] public ?string $account = null;

    public static function getNavigationLabel(): string { return __('Account statement'); }
    public function getTitle(): string { return __('Account statement'); }

    public function mount(): void
    {
        $this->from ??= now()->startOfYear()->toDateString();
        parent::mount();
    }

    protected function formState(): array { return parent::formState() + ['account' => $this->account]; }

    protected function filterFields(): array
    {
        return array_merge([
            Forms\Components\Select::make('account')->label(__('Account'))->options(fn () => Options::allAccounts())->searchable()->live()->columnSpan(2),
        ], parent::filterFields());
    }

    public function getAccount(): ?Account
    {
        return $this->account ? Account::where('company_id', $this->company()->id)->find($this->account) : null;
    }

    public function getData(): ?array
    {
        $a = $this->getAccount();

        return $a ? app(ReportService::class)->ledger($a, $this->from, $this->to, $this->branchId()) : null;
    }

    protected function exportRows(): array
    {
        $d = $this->getData();
        $rows = [['', '', __('Opening balance'), '', '', '', $d['opening'] ?? 0]];
        foreach ($d['rows'] ?? [] as $r) {
            $rows[] = [$r['date'], $r['number'], $r['description'], $r['account'], $r['debit'], $r['credit'], $r['balance']];
        }
        $rows[] = ['', '', __('Total'), '', $d['debit'] ?? 0, $d['credit'] ?? 0, $d['closing'] ?? 0];

        return [[__('Date'), __('Number'), __('Description'), __('Account'), __('Debit'), __('Credit'), __('Balance')], $rows];
    }
}
