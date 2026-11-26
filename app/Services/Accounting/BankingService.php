<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money;
use App\Support\Perm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Cash/bank transfers, cheque life cycle and bank reconciliation (requirements §4 "الصندوق والبنوك"). */
class BankingService
{
    public function __construct(private PostingService $posting) {}

    // ---------- Transfers between cash boxes and banks ----------

    public function saveTransfer(Transfer $t, User $user): Transfer
    {
        $this->posting->authorize($user, Perm::VOUCHERS_DRAFT);
        foreach (['fromAccount', 'toAccount'] as $rel) {
            $a = $t->{$rel};
            if (! $a || $a->company_id !== $t->company_id || ! ($a->is_cash || $a->is_bank) || ! $a->is_postable) {
                throw new AccountingException(__('Transfers are between cash boxes and bank accounts only.'));
            }
        }
        if ($t->from_account_id === $t->to_account_id) {
            throw new AccountingException(__('Choose two different accounts.'));
        }
        if (Money::toCents($t->amount) <= 0) {
            throw new AccountingException(__('The amount must be greater than zero.'));
        }
        $t->created_by ??= $user->id;
        $t->status = 'draft';
        $t->save();
        AuditLog::record($t->wasRecentlyCreated ? 'created' : 'updated', $t, null, $t->only(['date', 'amount', 'from_account_id', 'to_account_id']));

        return $t;
    }

    public function postTransfer(Transfer $t, User $user): Transfer
    {
        $this->posting->authorize($user, Perm::VOUCHERS_POST);

        return DB::transaction(function () use ($t, $user) {
            $t = Transfer::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status !== 'draft') {
                throw new AccountingException(__('Only drafts can be posted.'));
            }
            $amount = Money::fromCents(Money::toCents($t->amount));
            $desc = $t->description ?: __('Transfer').' '.$t->fromAccount->name_ar.' → '.$t->toAccount->name_ar;
            $entry = $this->posting->postGenerated($t, 'transfer', 'TRF', ['company_id' => $t->company_id, 'branch_id' => $t->branch_id, 'date' => $t->date->toDateString(), 'description' => $desc, 'reference' => $t->reference], [
                ['account_id' => $t->to_account_id, 'debit' => $amount, 'credit' => 0, 'description' => $desc],
                ['account_id' => $t->from_account_id, 'debit' => 0, 'credit' => $amount, 'description' => $desc],
            ], $user);
            $t->forceFill(['status' => 'posted', 'number' => $entry->number, 'journal_entry_id' => $entry->id, 'posted_by' => $user->id])->saveQuietly();
            AuditLog::record('posted', $t, ['status' => 'draft'], ['status' => 'posted', 'number' => $entry->number]);

            return $t;
        });
    }

    public function cancelTransfer(Transfer $t, User $user, string $reason): Transfer
    {
        $this->posting->authorize($user, Perm::VOUCHERS_CANCEL);

        return DB::transaction(function () use ($t, $user, $reason) {
            if ($t->status !== 'posted') {
                throw new AccountingException(__('Only posted documents can be cancelled.'));
            }
            $this->posting->reverse($t->journalEntry, $user, $reason, null, system: true);
            $t->status = 'cancelled';
            $t->save();
            AuditLog::record('cancelled', $t, ['status' => 'posted'], ['status' => 'cancelled'], $reason);

            return $t;
        });
    }

    // ---------- Cheques ----------

    /**
     * Move a cheque along its life cycle.
     * Received: in_box → deposited (Dr bank / Cr cheques under collection) → returned (Dr customer / Cr bank).
     *           in_box → returned (Dr customer / Cr cheques under collection).
     * Issued:   issued → cleared (Dr post-dated cheques / Cr bank) ; issued → returned (Dr post-dated cheques / Cr supplier).
     */
    public function chequeAction(Voucher $v, string $action, User $user, string $date, ?int $bankAccountId = null, ?string $reason = null): Voucher
    {
        $this->posting->authorize($user, Perm::BANKING_MANAGE);

        return DB::transaction(function () use ($v, $action, $user, $date, $bankAccountId, $reason) {
            $v = Voucher::whereKey($v->id)->lockForUpdate()->firstOrFail();
            $company = $v->company;
            $in = $company->systemAccount('cheques_in');
            $out = $company->systemAccount('cheques_out');
            $amount = Money::fromCents(Money::toCents($v->total));
            $bank = $bankAccountId ? Account::where('company_id', $v->company_id)->where('is_bank', true)->find($bankAccountId) : $v->cashAccount;
            $party = $v->party_id ? ['account_id' => $v->party->account_id, 'party_id' => $v->party_id] : ['account_id' => $v->counter_account_id];
            $desc = __('Cheque').' '.$v->cheque_number.' — '.$v->display_number;

            [$from, $to, $lines] = match ([$v->cheque_status, $action]) {
                ['in_box', 'deposit'] => ['in_box', 'deposited', [['account_id' => $bank?->id] + $this->dr($amount), ['account_id' => $in->id] + $this->cr($amount)]],
                ['in_box', 'return'] => ['in_box', 'returned', [$party + $this->dr($amount), ['account_id' => $in->id] + $this->cr($amount)]],
                ['deposited', 'return'] => ['deposited', 'returned', [$party + $this->dr($amount), ['account_id' => $v->chequeEntry?->lines()->where('debit', '>', 0)->value('account_id') ?? $bank?->id] + $this->cr($amount)]],
                ['issued', 'clear'] => ['issued', 'cleared', [['account_id' => $out->id] + $this->dr($amount), ['account_id' => $bank?->id] + $this->cr($amount)]],
                ['issued', 'return'] => ['issued', 'returned', [['account_id' => $out->id] + $this->dr($amount), $party + $this->cr($amount)]],
                default => throw new AccountingException(__('This cheque cannot be moved from :s.', ['s' => __(Voucher::CHEQUE_STATUSES[$v->cheque_status] ?? (string) $v->cheque_status)])),
            };
            if (in_array($action, ['deposit', 'clear'], true) && ! $bank?->is_bank) {
                throw new AccountingException(__('Choose a bank account.'));
            }
            if ($action === 'return' && blank($reason)) {
                throw new AccountingException(__('A reason is required.'));
            }
            $lines = array_map(fn ($l) => $l + ['description' => $desc], $lines);
            $entry = $this->posting->postGenerated($v, 'cheque', 'CHQ', ['company_id' => $v->company_id, 'branch_id' => $v->branch_id, 'date' => $date, 'description' => $desc, 'reference' => $v->cheque_number], $lines, $user);
            if ($to === 'returned') {
                foreach ($v->allocations as $a) { // the invoices it paid are open again
                    AuditLog::record('deallocated', $a->invoice, ['voucher' => $v->number, 'amount' => $a->amount], null, $reason);
                    $a->delete();
                }
            }
            $v->forceFill(['cheque_status' => $to, 'cheque_entry_id' => $entry->id])->save();
            AuditLog::record('cheque_'.$to, $v, ['cheque_status' => $from], ['cheque_status' => $to, 'entry' => $entry->number], $reason);

            return $v;
        });
    }

    private function dr(string $a): array { return ['debit' => $a, 'credit' => 0]; }
    private function cr(string $a): array { return ['debit' => 0, 'credit' => $a]; }

    // ---------- Bank reconciliation ----------

    /**
     * Read a bank statement file (CSV or Excel). Columns are detected from the header row:
     * date, description, reference, and either amount (signed) or debit/credit (withdrawal/deposit).
     */
    public function readStatementFile(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        $header = null;
        $rows = [];
        $find = fn (array $h, array $names) => collect($h)->search(fn ($c) => collect($names)->contains(fn ($n) => str_contains(mb_strtolower(trim((string) $c)), $n)));
        foreach ($sheet as $r) {
            if ($header === null) {
                $map = [
                    'date' => $find($r, ['date', 'تاريخ']),
                    'description' => $find($r, ['description', 'details', 'narration', 'بيان', 'وصف', 'تفاصيل']),
                    'reference' => $find($r, ['reference', 'ref', 'cheque', 'مرجع', 'رقم']),
                    'amount' => $find($r, ['amount', 'مبلغ']),
                    'debit' => $find($r, ['debit', 'withdrawal', 'مدين', 'سحب']),
                    'credit' => $find($r, ['credit', 'deposit', 'دائن', 'إيداع', 'ايداع']),
                ];
                if ($map['date'] !== false && ($map['amount'] !== false || $map['debit'] !== false || $map['credit'] !== false)) {
                    $header = $map;
                }

                continue;
            }
            $rawDate = $r[$header['date']] ?? null;
            if ($rawDate === null || $rawDate === '') {
                continue;
            }
            $date = is_numeric($rawDate) ? Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $rawDate)) : $this->parseDate((string) $rawDate);
            $num = fn ($i) => $i === false ? 0 : Money::toCents(str_replace([',', ' '], '', (string) ($r[$i] ?? '0')) ?: '0');
            $amount = $header['amount'] !== false ? $num($header['amount']) : $num($header['credit']) - $num($header['debit']);
            if ($amount === 0) {
                continue;
            }
            $rows[] = [
                'date' => $date->toDateString(),
                'description' => $header['description'] !== false ? trim((string) $r[$header['description']]) : null,
                'reference' => $header['reference'] !== false ? trim((string) $r[$header['reference']]) : null,
                'amount' => Money::fromCents($amount),
            ];
        }
        if ($header === null) {
            throw new AccountingException(__('Could not find the date and amount columns in the file.'));
        }

        return $rows;
    }

    private function parseDate(string $s): Carbon
    {
        $s = trim($s);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'd/m/y', 'd-M-Y', 'd M Y'] as $f) {
            try {
                $d = Carbon::createFromFormat('!'.$f, $s);
                if ($d && $d->format($f) === $s) {
                    return $d;
                }
            } catch (\Throwable) {
            }
        }

        return Carbon::parse($s);
    }

    public function importStatement(Account $account, array $rows, array $header, User $user, ?string $fileName = null): BankStatement
    {
        $this->posting->authorize($user, Perm::BANKING_MANAGE);
        if (! $account->is_bank) {
            throw new AccountingException(__('Choose a bank account.'));
        }

        return DB::transaction(function () use ($account, $rows, $header, $user, $fileName) {
            $st = BankStatement::create($header + ['company_id' => $account->company_id, 'account_id' => $account->id, 'file_name' => $fileName, 'created_by' => $user->id]);
            foreach ($rows as $r) {
                $st->lines()->create($r);
            }
            $this->autoMatch($st);
            AuditLog::record('imported', $st, null, ['lines' => count($rows), 'file' => $fileName]);

            return $st;
        });
    }

    /** Unreconciled book lines of the bank account (posted entries, up to the statement end). */
    public function openBookLines(BankStatement $st)
    {
        return JournalLine::query()->select('journal_lines.*')->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $st->account_id)->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.date', '<=', $st->date_to->toDateString())
            // reconciliation starts with the account's first imported statement; older items are in its opening balance
            ->where('e.date', '>=', BankStatement::where('account_id', $st->account_id)->min('date_from'))
            ->whereNotExists(fn ($q) => $q->from('bank_statement_lines as b')->whereColumn('b.journal_line_id', 'journal_lines.id'))
            ->with('entry')->orderBy('e.date')->get();
    }

    /** Match statement lines to book lines with the same amount, nearest date within 7 days (reference first). */
    public function autoMatch(BankStatement $st): int
    {
        $book = $this->openBookLines($st)->map(fn ($l) => ['line' => $l, 'cents' => Money::toCents($l->debit) - Money::toCents($l->credit), 'date' => $l->entry->date])->values()->all();
        $n = 0;
        foreach ($st->lines()->whereNull('journal_line_id')->get() as $s) {
            $cents = Money::toCents($s->amount);
            $best = null;
            foreach ($book as $i => $b) {
                if ($b === null || $b['cents'] !== $cents) {
                    continue;
                }
                $days = abs($b['date']->diffInDays($s->date));
                if ($days > 7) {
                    continue;
                }
                $score = $days - ($s->reference && str_contains((string) $b['line']->entry->reference.' '.$b['line']->entry->number, $s->reference) ? 100 : 0);
                if ($best === null || $score < $best[1]) {
                    $best = [$i, $score];
                }
            }
            if ($best) {
                $s->update(['journal_line_id' => $book[$best[0]]['line']->id]);
                $book[$best[0]] = null;
                $n++;
            }
        }

        return $n;
    }

    public function match(BankStatementLine $s, ?int $journalLineId, User $user): void
    {
        $this->posting->authorize($user, Perm::BANKING_MANAGE);
        if ($journalLineId) {
            $l = JournalLine::with('entry')->findOrFail($journalLineId);
            if ($l->account_id !== $s->statement->account_id) {
                throw new AccountingException(__('The entry line is not on this bank account.'));
            }
            if (Money::toCents($l->debit) - Money::toCents($l->credit) !== Money::toCents($s->amount)) {
                throw new AccountingException(__('Amounts differ: record the difference (bank charges, interest) with an entry or voucher first.'));
            }
        }
        $s->update(['journal_line_id' => $journalLineId]);
    }

    /** Reconciliation summary: book vs statement, and the items that explain the difference. */
    public function summary(BankStatement $st): array
    {
        $book = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $st->account_id)->whereIn('e.status', ['posted', 'reversed'])->where('e.date', '<=', $st->date_to->toDateString())
            ->selectRaw('COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c')->first();
        $bookBalance = Money::toCents((string) $book->d) - Money::toCents((string) $book->c);
        $lines = $st->lines()->with('journalLine.entry')->get();
        $statementMovement = $lines->sum(fn ($l) => Money::toCents($l->amount));
        $unmatchedStatement = $lines->whereNull('journal_line_id')->values();
        $outstanding = $this->openBookLines($st)->values();
        $outstandingCents = $outstanding->sum(fn ($l) => Money::toCents($l->debit) - Money::toCents($l->credit));
        $unmatchedCents = $unmatchedStatement->sum(fn ($l) => Money::toCents($l->amount));
        $closing = Money::toCents($st->closing_balance);

        return [
            'book_balance' => $bookBalance,
            'statement_closing' => $closing,
            'statement_check' => Money::toCents($st->opening_balance) + $statementMovement - $closing, // 0 when the file is complete
            'outstanding' => $outstanding, 'outstanding_cents' => $outstandingCents,
            'unmatched_statement' => $unmatchedStatement, 'unmatched_cents' => $unmatchedCents,
            // statement closing = book balance − book items not yet on the statement + statement items not yet in the books
            'difference' => $closing - ($bookBalance - $outstandingCents + $unmatchedCents),
            'matched' => $lines->whereNotNull('journal_line_id')->count(),
            'total' => $lines->count(),
        ];
    }
}
