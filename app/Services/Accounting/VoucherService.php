<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money;
use App\Support\Perm;
use Illuminate\Support\Facades\DB;

/**
 * Receipt and payment vouchers. Posting generates the automatic entry from the
 * table in requirements §3:
 *   Receipt:  Dr cash/bank (total)      Cr counter account (net) + Cr VAT output (if taxed)
 *   Payment:  Dr counter account (net) + Dr VAT input (if any)    Cr cash/bank (total)
 */
class VoucherService
{
    public function __construct(private PostingService $posting, private SequenceService $sequences) {}

    public function computeTotals(Voucher $v): void
    {
        $net = Money::toCents($v->amount);
        $tax = 0;
        $code = $v->taxCode;
        if ($code && $code->type === 'standard') {
            $tax = Money::percent($net, (string) $code->rate);
        }
        $v->tax_amount = Money::fromCents($tax);
        $v->total = Money::fromCents($net + $tax);
    }

    public function saveDraft(Voucher $v, User $user): Voucher
    {
        $this->posting->authorize($user, Perm::VOUCHERS_DRAFT);
        if ($v->exists && $v->getOriginal('status') !== 'draft') {
            throw new AccountingException(__('A posted voucher cannot be modified. Cancel it with a reversing entry.'));
        }
        $this->validateShape($v);
        $this->computeTotals($v);
        $before = $v->exists ? $v->getOriginal() : null;
        $v->created_by ??= $user->id;
        $v->status = 'draft';
        $v->save();
        AuditLog::record($before ? 'updated' : 'created', $v, $before, $v->toArray());

        return $v;
    }

    public function post(Voucher $v, User $user): Voucher
    {
        $this->posting->authorize($user, Perm::VOUCHERS_POST);

        return DB::transaction(function () use ($v, $user) {
            $v = Voucher::whereKey($v->id)->lockForUpdate()->firstOrFail();
            if ($v->status !== 'draft') {
                throw new AccountingException(__('Only draft vouchers can be posted.'));
            }
            $company = $v->company;
            if ($company->segregation_of_duties && $v->created_by === $user->id) {
                throw new AccountingException(__('Segregation of duties: the person who entered a document cannot post it.'));
            }
            if ($v->type === 'payment' && Money::toCents($v->total) > Money::toCents($company->voucher_approval_limit) && ! $user->can(Perm::VOUCHERS_OVER_LIMIT)) {
                throw new AccountingException(__('This payment exceeds the approval limit of :l and needs the chief accountant.', ['l' => Money::format($company->voucher_approval_limit)]));
            }
            $this->validateShape($v);
            $this->computeTotals($v);

            $entry = JournalEntry::create([
                'company_id' => $v->company_id,
                'branch_id' => $v->branch_id,
                'type' => 'voucher',
                'date' => $v->date->toDateString(),
                'description' => trim(Voucher::TYPES[$v->type].' — '.($v->party_name ?? '').' '.($v->description ?? '')),
                'source_type' => $v->getMorphClass(),
                'source_id' => $v->id,
                'created_by' => $v->created_by,
            ]);
            $this->posting->replaceLines($entry, $this->lines($v));

            $prefix = $v->type === 'receipt' ? 'RV' : 'PV';
            $number = $this->sequences->next($v->company_id, $prefix, (int) $v->date->format('Y'));
            $entry->forceFill(['number' => $number])->save();
            $this->posting->post($entry, $user, system: true);

            $v->forceFill(['status' => 'posted', 'number' => $number, 'journal_entry_id' => $entry->id, 'posted_by' => $user->id, 'posted_at' => now(),
                'cheque_status' => $v->payment_method === 'cheque' && $this->cashSideAccountId($v) !== $v->cash_account_id ? ($v->type === 'receipt' ? 'in_box' : 'issued') : null]);
            $v->saveQuietly(); // draft -> posted transition (guard is for changes after posting)
            AuditLog::record('posted', $v, ['status' => 'draft'], ['status' => 'posted', 'number' => $number, 'entry' => $entry->number]);
            app(InvoiceService::class)->allocateLinked($v, $user);

            return $v;
        });
    }

    /** Cancel a posted voucher: keeps its number with status "cancelled" and reverses its entry (§6.4). */
    public function cancel(Voucher $v, User $user, string $reason, ?string $date = null): Voucher
    {
        $this->posting->authorize($user, Perm::VOUCHERS_CANCEL);
        if (trim($reason) === '') {
            throw new AccountingException(__('A reason is required.'));
        }

        return DB::transaction(function () use ($v, $user, $reason, $date) {
            $v = Voucher::whereKey($v->id)->lockForUpdate()->firstOrFail();
            if ($v->status !== 'posted') {
                throw new AccountingException(__('Only posted vouchers can be cancelled.'));
            }
            $this->posting->reverse($v->journalEntry, $user, $reason, $date, system: true);
            foreach ($v->allocations as $a) {
                AuditLog::record('deallocated', $a->invoice, ['voucher' => $v->number, 'amount' => $a->amount], null, $reason);
                $a->delete();
            }
            $v->status = 'cancelled';
            $v->save();
            AuditLog::record('cancelled', $v, ['status' => 'posted'], ['status' => 'cancelled'], $reason);

            return $v;
        });
    }

    public function deleteDraft(Voucher $v, User $user): void
    {
        $this->posting->authorize($user, Perm::VOUCHERS_DRAFT);
        AuditLog::record('deleted', $v, $v->toArray(), null);
        $v->delete();
    }

    private function validateShape(Voucher $v): void
    {
        if (Money::toCents($v->amount) <= 0) {
            throw new AccountingException(__('Amount must be greater than zero.'));
        }
        $cash = $v->cashAccount;
        if (! $cash || ! ($cash->is_cash || $cash->is_bank)) {
            throw new AccountingException(__('Choose a cash box or bank account.'));
        }
        if ($v->counter_account_id === $v->cash_account_id) {
            throw new AccountingException(__('The counter account must differ from the cash account.'));
        }
        if ($v->party_id) {
            $party = $v->party;
            if (! $party || $party->company_id !== $v->company_id) {
                throw new AccountingException(__('Party does not belong to this company.'));
            }
            $v->counter_account_id = $party->account_id; // the party is a sub-ledger of its control account
        }
        $code = $v->taxCode;
        if ($code && $code->type === 'standard') {
            $needed = $v->type === 'receipt' ? $code->output_account_id : $code->input_account_id;
            if (! $needed) {
                throw new AccountingException(__('Tax code :c has no VAT account configured.', ['c' => $code->code]));
            }
        }
    }

    /** The journal lines a voucher generates. */
    /**
     * Cheques do not touch the bank on the voucher date: a received cheque waits in "cheques under
     * collection" until deposited; an issued cheque sits in "post-dated cheques payable" until it clears.
     */
    public function cashSideAccountId(Voucher $v): int
    {
        if ($v->payment_method === 'cheque') {
            $acc = $v->company->systemAccount($v->type === 'receipt' ? 'cheques_in' : 'cheques_out');
            if ($acc) {
                return $acc->id;
            }
        }

        return $v->cash_account_id;
    }

    public function lines(Voucher $v): array
    {
        $net = Money::toCents($v->amount);
        $tax = Money::toCents($v->tax_amount);
        $total = $net + $tax;
        $code = $v->taxCode;
        $desc = $v->description ?: $v->party_name;
        $base = ['party_id' => $v->party_id, 'cost_center_id' => $v->cost_center_id, 'tax_code_id' => $code?->id, 'tax_direction' => $code ? ($v->type === 'receipt' ? 'sale' : 'purchase') : null];
        $cashSide = $this->cashSideAccountId($v);

        if ($v->type === 'receipt') {
            $lines = [
                ['account_id' => $cashSide, 'debit' => Money::fromCents($total), 'credit' => 0, 'description' => $desc],
                ['account_id' => $v->counter_account_id, 'debit' => 0, 'credit' => Money::fromCents($net), 'description' => $desc] + $base,
            ];
            if ($tax > 0) {
                $lines[] = ['account_id' => $code->output_account_id, 'debit' => 0, 'credit' => Money::fromCents($tax), 'description' => __('Output VAT'), 'is_tax_line' => true, 'tax_base' => Money::fromCents($net)] + $base;
            }
        } else {
            $lines = [
                ['account_id' => $v->counter_account_id, 'debit' => Money::fromCents($net), 'credit' => 0, 'description' => $desc] + $base,
            ];
            if ($tax > 0) {
                $lines[] = ['account_id' => $code->input_account_id, 'debit' => Money::fromCents($tax), 'credit' => 0, 'description' => __('Input VAT'), 'is_tax_line' => true, 'tax_base' => Money::fromCents($net)] + $base;
            }
            $lines[] = ['account_id' => $cashSide, 'debit' => 0, 'credit' => Money::fromCents($total), 'description' => $desc];
        }

        return $lines;
    }
}
