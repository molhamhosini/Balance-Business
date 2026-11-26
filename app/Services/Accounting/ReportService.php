<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * All reports are built from posted entries only (§7). Amounts are returned in fils (int).
 */
class ReportService
{
    private function base(Company $company, ?int $branchId)
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', $company->id)
            ->whereIn('e.status', ['posted', 'reversed'])
            ->when($branchId, fn ($q) => $q->where('e.branch_id', $branchId));
    }

    /** Per leaf account: [account_id => ['d'=>int,'c'=>int]] between dates (inclusive; null = open). */
    public function sums(Company $company, ?string $from, ?string $to, ?int $branchId = null, bool $excludeClosing = false): array
    {
        $rows = $this->base($company, $branchId)
            ->when($from, fn ($q) => $q->where('e.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('e.date', '<=', $to))
            ->when($excludeClosing, fn ($q) => $q->where('e.type', '!=', 'closing'))
            ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) d, SUM(l.credit) c')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->account_id] = ['d' => Money::toCents((string) $r->d), 'c' => Money::toCents((string) $r->c)];
        }

        return $out;
    }

    public function fiscalYearStart(Company $company, string $date): ?string
    {
        return FiscalYear::where('company_id', $company->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->value('start_date');
    }

    /**
     * Trial balance at any level. Income-statement accounts open from the fiscal-year start;
     * balance-sheet accounts are cumulative.
     */
    public function trialBalance(Company $company, string $from, string $to, int $level = 4, ?int $branchId = null): array
    {
        $accounts = Account::where('company_id', $company->id)->orderBy('code')->get()->keyBy('id');
        $fyStart = $this->fiscalYearStart($company, $from);
        $openingBs = $this->sums($company, null, $this->dayBefore($from), $branchId);
        $openingIs = $fyStart && $fyStart < $from ? $this->sums($company, $fyStart, $this->dayBefore($from), $branchId) : [];
        $period = $this->sums($company, $from, $to, $branchId);

        $roll = [];
        foreach ($accounts as $a) {
            $op = $a->statement === 'balance_sheet' ? ($openingBs[$a->id] ?? null) : ($openingIs[$a->id] ?? null);
            $pr = $period[$a->id] ?? null;
            if (! $op && ! $pr) {
                continue;
            }
            $target = $this->ancestorAtLevel($a, $accounts, $level);
            $r = &$roll[$target->id];
            $r ??= ['account' => $target, 'od' => 0, 'oc' => 0, 'pd' => 0, 'pc' => 0];
            $r['od'] += $op['d'] ?? 0;
            $r['oc'] += $op['c'] ?? 0;
            $r['pd'] += $pr['d'] ?? 0;
            $r['pc'] += $pr['c'] ?? 0;
            unset($r);
        }

        $rows = collect($roll)->map(function ($r) {
            $ob = $r['od'] - $r['oc'];
            $cb = $ob + $r['pd'] - $r['pc'];

            return $r + ['opening_dr' => max($ob, 0), 'opening_cr' => max(-$ob, 0), 'closing_dr' => max($cb, 0), 'closing_cr' => max(-$cb, 0)];
        })->sortBy(fn ($r) => $r['account']->code)->values();

        $totals = [];
        foreach (['od', 'oc', 'pd', 'pc', 'opening_dr', 'opening_cr', 'closing_dr', 'closing_cr'] as $k) {
            $totals[$k] = $rows->sum($k);
        }

        return ['rows' => $rows, 'totals' => $totals, 'balanced' => $totals['closing_dr'] === $totals['closing_cr'] && $totals['pd'] === $totals['pc']];
    }

    /** Account statement / ledger with opening balance and running balance. Works for parent accounts too. */
    public function ledger(Account $account, string $from, string $to, ?int $branchId = null): array
    {
        $company = $account->company;
        $ids = $account->descendantIds();
        $openFrom = $account->statement === 'income_statement' ? $this->fiscalYearStart($company, $from) : null;

        $op = $this->base($company, $branchId)->whereIn('l.account_id', $ids)
            ->where('e.date', '<', $from)->when($openFrom, fn ($q) => $q->where('e.date', '>=', $openFrom))
            ->selectRaw('COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c')->first();
        $opening = Money::toCents((string) $op->d) - Money::toCents((string) $op->c);

        $lines = $this->base($company, $branchId)->whereIn('l.account_id', $ids)
            ->whereBetween('e.date', [$from, $to])
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->orderBy('e.date')->orderBy('e.id')->orderBy('l.sort')
            ->get(['e.id as entry_id', 'e.number', 'e.date', 'e.description as entry_description', 'e.status', 'l.description', 'l.debit', 'l.credit', 'a.code', 'a.name_ar']);

        $bal = $opening;
        $rows = $lines->map(function ($l) use (&$bal) {
            $d = Money::toCents((string) $l->debit);
            $c = Money::toCents((string) $l->credit);
            $bal += $d - $c;

            return ['entry_id' => $l->entry_id, 'number' => $l->number, 'date' => $l->date, 'description' => $l->description ?: $l->entry_description, 'account' => $l->code.' '.$l->name_ar, 'status' => $l->status, 'debit' => $d, 'credit' => $c, 'balance' => $bal];
        });

        return ['opening' => $opening, 'rows' => $rows, 'debit' => $rows->sum('debit'), 'credit' => $rows->sum('credit'), 'closing' => $bal];
    }

    /** Journal book: entries in date order with their lines. */
    public function journal(Company $company, string $from, string $to, ?int $branchId = null): Collection
    {
        return \App\Models\JournalEntry::with(['lines.account', 'branch'])
            ->where('company_id', $company->id)->effective()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
    }

    /** Income statement grouped by account class and level-2/3 heading, with an optional comparison period. */
    public function incomeStatement(Company $company, string $from, string $to, ?int $branchId = null): array
    {
        $accounts = Account::where('company_id', $company->id)->where('statement', 'income_statement')->get()->keyBy('id');
        $sums = $this->sums($company, $from, $to, $branchId, excludeClosing: true);
        $groups = ['revenue' => [], 'cogs' => [], 'expense' => []];
        $tot = ['revenue' => 0, 'cogs' => 0, 'expense' => 0];
        foreach ($sums as $id => $s) {
            if (! isset($accounts[$id])) {
                continue;
            }
            $a = $accounts[$id];
            $amount = $a->class === 'revenue' ? $s['c'] - $s['d'] : $s['d'] - $s['c'];
            $head = $this->ancestorAtLevel($a, $accounts, 3);
            $groups[$a->class][$head->code] ??= ['label' => $head->code.' '.$head->name, 'amount' => 0];
            $groups[$a->class][$head->code]['amount'] += $amount;
            $tot[$a->class] += $amount;
        }
        foreach ($groups as &$g) {
            ksort($g);
        }
        $gross = $tot['revenue'] - $tot['cogs'];

        return ['groups' => $groups, 'totals' => $tot, 'gross' => $gross, 'net' => $gross - $tot['expense']];
    }

    /** Balance sheet at a date; current-year result shown as its own equity line. */
    public function balanceSheet(Company $company, string $asOf, ?int $branchId = null): array
    {
        $accounts = Account::where('company_id', $company->id)->get()->keyBy('id');
        $sums = $this->sums($company, null, $asOf, $branchId);
        $groups = ['asset' => [], 'liability' => [], 'equity' => []];
        $tot = ['asset' => 0, 'liability' => 0, 'equity' => 0];
        $result = 0;
        foreach ($sums as $id => $s) {
            $a = $accounts[$id] ?? null;
            if (! $a) {
                continue;
            }
            if ($a->statement === 'income_statement') {
                $result += $s['c'] - $s['d'];

                continue;
            }
            $amount = $a->class === 'asset' ? $s['d'] - $s['c'] : $s['c'] - $s['d'];
            $head = $this->ancestorAtLevel($a, $accounts, 3);
            $groups[$a->class][$head->code] ??= ['label' => $head->code.' '.$head->name, 'amount' => 0];
            $groups[$a->class][$head->code]['amount'] += $amount;
            $tot[$a->class] += $amount;
        }
        foreach ($groups as &$g) {
            ksort($g);
        }
        $groups['equity']['zzz'] = ['label' => __('Profit / loss not yet closed'), 'amount' => $result];
        $tot['equity'] += $result;

        return ['groups' => $groups, 'totals' => $tot, 'balanced' => $tot['asset'] === $tot['liability'] + $tot['equity']];
    }

    /**
     * VAT 201 boxes (template supplied by the client). Base amounts come from the base
     * lines tagged with a tax code; VAT from the VAT lines. Credit-side base = supply,
     * debit-side base = expense. Standard-rated supplies split by the branch's emirate.
     */
    public function vat201(Company $company, string $from, string $to): array
    {
        $boxes = [];
        foreach (array_merge(array_values(Branch::VAT_BOX), ['2', '3', '4', '5', '6', '7', '9', '10']) as $b) {
            $boxes[$b] = ['amount' => 0, 'vat' => 0, 'adjustment' => 0];
        }
        $rows = $this->base($company, null)->join('tax_codes as t', 't.id', '=', 'l.tax_code_id')
            ->join('branches as b', 'b.id', '=', 'e.branch_id')
            ->whereBetween('e.date', [$from, $to])->whereNotNull('l.tax_direction')
            ->get(['t.type', 'b.emirate', 'l.debit', 'l.credit', 'l.is_tax_line', 'l.tax_direction']);

        foreach ($rows as $r) {
            $d = Money::toCents((string) $r->debit);
            $c = Money::toCents((string) $r->credit);
            $sale = $r->tax_direction === 'sale';
            $box = $sale ? $this->saleBox($r) : $this->purchaseBox($r);
            if (! $box) {
                continue;
            }
            // Reversals carry the opposite side, so they net off automatically.
            $value = $sale ? $c - $d : $d - $c;
            $boxes[$box][$r->is_tax_line ? 'vat' : 'amount'] += $value;
            if (! $sale && ! $r->is_tax_line && $r->type === 'reverse_charge') {
                $boxes['3']['amount'] += $value; // self-assessed: the base appears on both sides of the return
            }
        }

        $salesBoxes = array_merge(array_values(Branch::VAT_BOX), ['2', '3', '4', '5', '6', '7']);
        $sum = fn (array $keys, string $f) => array_sum(array_map(fn ($b) => $boxes[$b][$f], $keys));
        $boxes['8'] = ['amount' => $sum($salesBoxes, 'amount'), 'vat' => $sum($salesBoxes, 'vat'), 'adjustment' => 0];
        $boxes['11'] = ['amount' => $sum(['9', '10'], 'amount'), 'vat' => $sum(['9', '10'], 'vat'), 'adjustment' => 0];
        $boxes['12'] = ['amount' => 0, 'vat' => $boxes['8']['vat'], 'adjustment' => 0];
        $boxes['13'] = ['amount' => 0, 'vat' => $boxes['11']['vat'], 'adjustment' => 0];
        $boxes['14'] = ['amount' => 0, 'vat' => $boxes['12']['vat'] - $boxes['13']['vat'], 'adjustment' => 0];

        return $boxes;
    }

    /** Box labels exactly as in the client's VAT 201 template. */
    public const VAT201_LABELS = [
        '1.1' => 'التوريدات الخاضعة للنسبة الأساسية في أبو ظبي',
        '1.2' => 'التوريدات الخاضعة للنسبة الأساسية في دبي',
        '1.3' => 'التوريدات الخاضعة للنسبة الأساسية في الشارقة',
        '1.4' => 'التوريدات الخاضعة للنسبة الأساسية في عجمان',
        '1.5' => 'التوريدات الخاضعة للنسبة الأساسية في أم القيوين',
        '1.6' => 'التوريدات الخاضعة للنسبة الأساسية في رأس الخيمة',
        '1.7' => 'التوريدات الخاضعة للنسبة الأساسية في الفجيرة',
        '2' => 'المبالغ التي تم ردها للسياح بناءً على نظام رد الضريبة للسياح',
        '3' => 'التوريدات الخاضعة لآلية الاحتساب العكسي',
        '4' => 'التوريدات الخاضعة لنسبة الصفر',
        '5' => 'التوريدات المعفاة',
        '6' => 'السلع المستوردة إلى الإمارات',
        '7' => 'التعديلات على السلع المستوردة إلى الإمارات',
        '8' => 'المجموع',
        '9' => 'النفقات الخاضعة للنسبة الأساسية',
        '10' => 'التوريدات الخاضعة لآلية الاحتساب العكسي',
        '11' => 'المجموع',
        '12' => 'مجموع الضريبة المستحقة عن الفترة الضريبية',
        '13' => 'مجموع الضريبة القابلة للاسترداد التابعة للفترة الضريبية',
        '14' => 'الضريبة المستحقة الدفع للفترة الضريبية',
    ];

    private function saleBox(object $r): ?string
    {
        return match ($r->type) {
            'standard' => Branch::VAT_BOX[$r->emirate] ?? null,
            'zero' => '4',
            'exempt' => '5',
            'reverse_charge' => '3',
            default => null,
        };
    }

    private function purchaseBox(object $r): ?string
    {
        return match ($r->type) {
            'standard' => '9',
            'reverse_charge' => '10',
            default => null,
        };
    }

    /** Customer / supplier statement from the sub-ledger (posted entries carrying party_id). */
    public function partyStatement(\App\Models\Party $party, string $from, string $to): array
    {
        $opening = $party->balance($this->dayBefore($from));
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.party_id', $party->id)->whereIn('e.status', ['posted', 'reversed'])
            ->whereBetween('e.date', [$from, $to])->orderBy('e.date')->orderBy('e.id')->orderBy('l.id')
            ->get(['e.id as entry_id', 'e.date', 'e.number', 'e.description as entry_description', 'l.description', 'l.debit', 'l.credit', 'e.source_type', 'e.source_id']);
        $bal = $opening;
        $dr = $cr = 0;
        $out = [];
        foreach ($rows as $r) {
            $d = Money::toCents((string) $r->debit);
            $c = Money::toCents((string) $r->credit);
            $bal += $d - $c;
            $dr += $d;
            $cr += $c;
            $out[] = ['entry_id' => $r->entry_id, 'date' => (string) $r->date, 'number' => $r->number, 'description' => $r->entry_description ?: $r->description, 'debit' => $d, 'credit' => $c, 'balance' => $bal];
        }

        return ['opening' => $opening, 'rows' => $out, 'debit' => $dr, 'credit' => $cr, 'closing' => $bal];
    }

    public const AGING_BUCKETS = ['current' => 'غير مستحق', '1-7' => '1-7', '8-15' => '8-15', '16-30' => '16-30', '31-60' => '31-60', '61-90' => '61-90', '90+' => '+90'];

    /**
     * Aging of open invoices by days past the due date, per party. Unallocated payments and credit notes
     * (advances) are shown in their own column so the net equals the sub-ledger balance.
     */
    public function aging(Company $company, string $type, string $asOf, ?int $branchId = null): array
    {
        $kinds = $type === 'customer' ? ['sale', 'purchase_return'] : ['purchase', 'sale_return'];
        $invoices = \App\Models\Invoice::with('party')->where('company_id', $company->id)->where('status', 'posted')->where('payment', 'credit')
            ->whereIn('kind', $kinds)->where('date', '<=', $asOf)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereHas('party', fn ($q) => $q->where('type', $type))->get();
        $parties = [];
        $blank = array_fill_keys(array_keys(self::AGING_BUCKETS), 0) + ['unapplied' => 0, 'total' => 0];
        $as = \Carbon\Carbon::parse($asOf);
        foreach ($invoices as $inv) {
            $open = $inv->openCents();
            if ($open === 0) {
                continue;
            }
            $days = $inv->due_date ? (int) $inv->due_date->diffInDays($as, false) : 0;
            $bucket = match (true) {
                $days <= 0 => 'current', $days <= 7 => '1-7', $days <= 15 => '8-15', $days <= 30 => '16-30',
                $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+',
            };
            $parties[$inv->party_id] ??= ['party' => $inv->party, 'invoices' => []] + $blank;
            $parties[$inv->party_id][$bucket] += $open;
            $parties[$inv->party_id]['total'] += $open;
            $parties[$inv->party_id]['invoices'][] = ['id' => $inv->id, 'number' => $inv->number, 'date' => $inv->date->toDateString(), 'due' => $inv->due_date?->toDateString(), 'days' => max(0, $days), 'open' => $open, 'bucket' => $bucket];
        }
        // Unapplied credits: the sub-ledger balance not explained by open invoices (advances, unallocated notes).
        $sign = $type === 'customer' ? 1 : -1;
        foreach (\App\Models\Party::where('company_id', $company->id)->where('type', $type)->get() as $p) {
            $balance = $sign * $p->balance($asOf);
            $explained = $parties[$p->id]['total'] ?? 0;
            $diff = $balance - $explained;
            if ($diff !== 0) {
                $parties[$p->id] ??= ['party' => $p, 'invoices' => []] + $blank;
                $parties[$p->id]['unapplied'] += $diff;
                $parties[$p->id]['total'] += $diff;
            }
        }
        uasort($parties, fn ($a, $b) => strcmp($a['party']->code, $b['party']->code));
        $totals = $blank;
        foreach ($parties as $row) {
            foreach (array_keys($blank) as $k) {
                $totals[$k] += $row[$k];
            }
        }

        return ['rows' => array_values($parties), 'totals' => $totals];
    }

    private function ancestorAtLevel(Account $a, Collection $accounts, int $level): Account
    {
        $cur = $a;
        while ($cur->level > $level && $cur->parent_id && isset($accounts[$cur->parent_id])) {
            $cur = $accounts[$cur->parent_id];
        }

        return $cur;
    }

    private function dayBefore(string $date): string
    {
        return \Carbon\Carbon::parse($date)->subDay()->toDateString();
    }
}
