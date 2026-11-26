<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Money;
use App\Support\Perm;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PeriodService
{
    public function __construct(private PostingService $posting) {}

    /** Fiscal year with 12 monthly periods from any start date (§1). */
    public function createYear(Company $company, string $startDate, ?string $name = null): FiscalYear
    {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = $start->addMonthsNoOverflow(12)->subDay();
        $overlap = FiscalYear::where('company_id', $company->id)
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists();
        if ($overlap) {
            throw new AccountingException(__('This fiscal year overlaps an existing one.'));
        }

        return DB::transaction(function () use ($company, $start, $end, $name) {
            $year = FiscalYear::create([
                'company_id' => $company->id,
                'name' => $name ?? ($start->year === $end->year ? (string) $start->year : $start->year.'/'.$end->year),
                'start_date' => $start, 'end_date' => $end,
            ]);
            for ($i = 0; $i < 12; $i++) {
                $ps = $start->addMonthsNoOverflow($i);
                FiscalPeriod::create([
                    'company_id' => $company->id, 'fiscal_year_id' => $year->id, 'number' => $i + 1,
                    'start_date' => $ps, 'end_date' => $ps->addMonthsNoOverflow(1)->subDay(),
                ]);
            }

            return $year;
        });
    }

    public function close(FiscalPeriod $period, User $user): void
    {
        $this->posting->authorize($user, Perm::PERIODS_CLOSE);
        if (! $period->isOpen()) {
            return;
        }
        $drafts = JournalEntry::where('company_id', $period->company_id)->where('status', 'draft')
            ->whereBetween('date', [$period->start_date, $period->end_date])->count();
        $period->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now()]);
        AuditLog::record('period_closed', $period, ['status' => 'open'], ['status' => 'closed', 'drafts_left' => $drafts]);
    }

    public function reopen(FiscalPeriod $period, User $user, string $reason): void
    {
        $this->posting->authorize($user, Perm::PERIODS_REOPEN);
        if (trim($reason) === '') {
            throw new AccountingException(__('A reason is required to reopen a period.'));
        }
        if ($period->fiscalYear->isClosed()) {
            throw new AccountingException(__('The fiscal year is closed.'));
        }
        $period->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
        AuditLog::record('period_reopened', $period, ['status' => 'closed'], ['status' => 'open'], $reason);
    }

    /**
     * Year-end close (§6.6): one closing entry moving every revenue, COGS and expense
     * balance to retained earnings, dated the last day of the year; then periods are
     * locked and the next year is opened. Balance-sheet balances carry forward by
     * construction because balance-sheet reports are cumulative.
     */
    public function closeYear(FiscalYear $year, User $user): JournalEntry
    {
        $this->posting->authorize($user, Perm::YEAR_CLOSE);
        if ($year->isClosed()) {
            throw new AccountingException(__('The fiscal year is already closed.'));
        }
        $company = $year->company;
        $re = $company->systemAccount('retained_earnings')
            ?? throw new AccountingException(__('Bind the retained earnings account in settings first.'));
        if (JournalEntry::where('company_id', $company->id)->where('status', 'draft')->whereBetween('date', [$year->start_date, $year->end_date])->exists()) {
            throw new AccountingException(__('Post or delete all drafts in this year before closing it.'));
        }
        $branch = $company->branches()->orderBy('id')->firstOrFail();

        return DB::transaction(function () use ($year, $user, $company, $re, $branch) {
            // Ensure the last period is open for the closing entry.
            $last = $year->periods()->orderByDesc('number')->first();
            $last->update(['status' => 'open']);

            $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('e.company_id', $company->id)->whereIn('e.status', ['posted', 'reversed'])
                ->whereBetween('e.date', [$year->start_date->toDateString(), $year->end_date->toDateString()])
                ->where('a.statement', 'income_statement')
                ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) d, SUM(l.credit) c')->get();

            $lines = [];
            $net = 0;
            foreach ($rows as $r) {
                $bal = Money::toCents((string) $r->d) - Money::toCents((string) $r->c);
                if ($bal === 0) {
                    continue;
                }
                $lines[] = ['account_id' => $r->account_id, 'debit' => $bal < 0 ? Money::fromCents(-$bal) : 0, 'credit' => $bal > 0 ? Money::fromCents($bal) : 0];
                $net += $bal;
            }
            if ($net !== 0) {
                $lines[] = ['account_id' => $re->id, 'debit' => $net > 0 ? Money::fromCents($net) : 0, 'credit' => $net < 0 ? Money::fromCents(-$net) : 0, 'description' => __('Net result of the year')];
            }

            $entry = JournalEntry::create([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'type' => 'closing',
                'date' => $year->end_date->toDateString(), 'description' => __('Year-end closing :y', ['y' => $year->name]),
                'source_type' => $year->getMorphClass(), 'source_id' => $year->id, 'created_by' => $user->id,
            ]);
            if (count($lines) >= 2) {
                $this->posting->replaceLines($entry, $lines);
                $this->posting->post($entry, $user, system: true);
            }

            $year->periods()->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now()]);
            $year->update(['status' => 'closed', 'closing_entry_id' => $entry->id]);
            AuditLog::record('year_closed', $year, ['status' => 'open'], ['status' => 'closed', 'entry' => $entry->number, 'net' => Money::fromCents(-$net)]);

            $next = $year->end_date->copy()->addDay()->toDateString();
            if (! FiscalYear::where('company_id', $company->id)->whereDate('start_date', $next)->exists()) {
                $this->createYear($company, $next);
            }

            return $entry;
        });
    }
}
