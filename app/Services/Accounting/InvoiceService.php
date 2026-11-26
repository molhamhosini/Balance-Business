<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money;
use App\Support\Perm;
use Illuminate\Support\Facades\DB;

/**
 * Sales and purchase invoices and their returns. Posting produces the entries in
 * requirements §3, with VAT calculated and stored per line:
 *   Sale (credit)       Dr customer (gross)   Cr revenue lines (net) + Cr output VAT
 *   Sale (cash)         Dr cash / bank        Cr revenue lines + Cr output VAT
 *   Sales return / CN   the mirror of a sale (revenue lines usually 4114 returns or 4115 discounts)
 *   Purchase            Dr expense / inventory lines + Dr input VAT    Cr supplier or cash
 *   Purchase return     the mirror of a purchase
 *   Reverse charge      Dr input VAT and Cr output VAT on the same base (self-assessed; nets to 0)
 */
class InvoiceService
{
    /** Warnings raised while posting (e.g. credit limit exceeded in "warn" mode). */
    public array $warnings = [];

    public function __construct(private PostingService $posting, private SequenceService $sequences) {}

    /** Line amounts in fils: [net, tax, total] (reverse-charge tax is not added to the total). */
    public function lineAmounts(string|float $qty, string|float $price, ?TaxCode $code): array
    {
        // qty has 3 decimals, price 4 → product in 1e-7; convert to fils (1e-2) with half-up rounding.
        $q = $this->scaled((string) $qty, 3);
        $p = $this->scaled((string) $price, 4);
        $raw = $q * $p;
        $net = intdiv(abs($raw) + 50000, 100000) * ($raw < 0 ? -1 : 1);
        $tax = $code && in_array($code->type, ['standard', 'reverse_charge'], true) ? Money::percent($net, (string) $code->rate) : 0;
        $added = $code && $code->type === 'standard' ? $tax : 0;

        return [$net, $tax, $net + $added];
    }

    private function scaled(string $v, int $dp): int
    {
        $v = trim(str_replace(',', '', $v));
        $neg = str_starts_with($v, '-');
        [$i, $f] = array_pad(explode('.', ltrim($v, '+-'), 2), 2, '');
        $n = (int) ($i ?: '0') * (10 ** $dp) + (int) str_pad(substr($f, 0, $dp), $dp, '0');

        return $neg ? -$n : $n;
    }

    public function saveDraft(Invoice $inv, array $lines, User $user): Invoice
    {
        $this->posting->authorize($user, Perm::INVOICES_DRAFT);
        if ($inv->exists && $inv->getOriginal('status') !== 'draft') {
            throw new AccountingException(__('A posted invoice cannot be modified. Issue a credit note instead.'));
        }
        $this->validateHeader($inv);
        if (! $lines) {
            throw new AccountingException(__('Add at least one line.'));
        }

        return DB::transaction(function () use ($inv, $lines, $user) {
            $before = $inv->exists ? $inv->getOriginal() : null;
            $inv->created_by ??= $user->id;
            $inv->status = 'draft';
            if (! $inv->due_date) {
                $inv->due_date = $inv->date->copy()->addDays($inv->party->payment_terms_days);
            }
            $inv->save();
            $inv->lines()->delete();
            $sub = $taxTotal = 0;
            foreach (array_values($lines) as $i => $l) {
                $code = ! empty($l['tax_code_id']) ? TaxCode::find($l['tax_code_id']) : null;
                if ($code && $code->type === 'reverse_charge' && $inv->isSale()) {
                    throw new AccountingException(__('Reverse charge applies to purchases only.'));
                }
                [$net, $tax, $total] = $this->lineAmounts($l['quantity'] ?? 1, $l['unit_price'] ?? 0, $code);
                if ($net <= 0) {
                    throw new AccountingException(__('Line :n: quantity and price must be greater than zero.', ['n' => $i + 1]));
                }
                InvoiceLine::create([
                    'invoice_id' => $inv->id, 'account_id' => $l['account_id'], 'description' => $l['description'] ?: '-',
                    'quantity' => $l['quantity'] ?? 1, 'unit' => $l['unit'] ?? null, 'unit_price' => $l['unit_price'] ?? 0,
                    'tax_code_id' => $code?->id, 'net' => Money::fromCents($net), 'tax' => Money::fromCents($tax), 'total' => Money::fromCents($total), 'sort' => $i,
                ]);
                $sub += $net;
                $taxTotal += $total - $net;
            }
            $inv->forceFill(['subtotal' => Money::fromCents($sub), 'tax_total' => Money::fromCents($taxTotal), 'total' => Money::fromCents($sub + $taxTotal)])->saveQuietly();
            AuditLog::record($before ? 'updated' : 'created', $inv, $before ? array_intersect_key($before, array_flip(['date', 'party_id', 'total'])) : null, $inv->only(['date', 'party_id', 'total']));

            return $inv->fresh();
        });
    }

    private function validateHeader(Invoice $inv): void
    {
        $party = $inv->party;
        if (! $party || $party->company_id !== $inv->company_id) {
            throw new AccountingException(__('Choose a customer or supplier of this company.'));
        }
        if ($party->type !== $inv->partyType()) {
            throw new AccountingException($inv->isSale() ? __('Sales documents need a customer.') : __('Purchase documents need a supplier.'));
        }
        if ($inv->payment === 'cash') {
            $cash = $inv->cashAccount;
            if (! $cash || ! ($cash->is_cash || $cash->is_bank)) {
                throw new AccountingException(__('Choose a cash box or bank account.'));
            }
        }
        if ($inv->kind === 'purchase') {
            if (blank($inv->supplier_invoice_number)) {
                throw new AccountingException(__('Enter the supplier invoice number.'));
            }
            $dup = Invoice::where('company_id', $inv->company_id)->where('party_id', $inv->party_id)->where('kind', 'purchase')
                ->where('supplier_invoice_number', $inv->supplier_invoice_number)->when($inv->exists, fn ($q) => $q->whereKeyNot($inv->id))->first();
            if ($dup) {
                throw new AccountingException(__('Supplier invoice :n is already recorded (:d).', ['n' => $inv->supplier_invoice_number, 'd' => $dup->display_number]));
            }
        }
        if ($inv->original_invoice_id) {
            $o = $inv->original;
            $expected = ['sale_return' => 'sale', 'purchase_return' => 'purchase'][$inv->kind] ?? null;
            if (! $o || $o->kind !== $expected || $o->party_id !== $inv->party_id || $o->status !== 'posted') {
                throw new AccountingException(__('The original invoice must be a posted invoice of the same party.'));
            }
        }
    }

    public function post(Invoice $inv, User $user): Invoice
    {
        $this->posting->authorize($user, Perm::INVOICES_POST);
        $this->warnings = [];

        return DB::transaction(function () use ($inv, $user) {
            $inv = Invoice::whereKey($inv->id)->lockForUpdate()->firstOrFail();
            if ($inv->status !== 'draft') {
                throw new AccountingException(__('Only draft invoices can be posted.'));
            }
            if ($inv->company->segregation_of_duties && $inv->created_by === $user->id) {
                throw new AccountingException(__('Segregation of duties: the person who entered a document cannot post it.'));
            }
            if (! $user->canUseBranch($inv->branch)) {
                throw new AccountingException(__('You are not allowed to work on this branch.'));
            }
            $this->validateHeader($inv);
            if ($inv->lines()->count() === 0 || Money::toCents($inv->total) <= 0) {
                throw new AccountingException(__('Add at least one line.'));
            }
            $this->checkCreditLimit($inv, $user);

            $entry = JournalEntry::create([
                'company_id' => $inv->company_id, 'branch_id' => $inv->branch_id, 'type' => 'invoice',
                'date' => $inv->date->toDateString(),
                'description' => __(Invoice::KINDS[$inv->kind]).' — '.$inv->party->name.($inv->supplier_invoice_number ? ' ('.$inv->supplier_invoice_number.')' : ''),
                'reference' => $inv->supplier_invoice_number,
                'source_type' => $inv->getMorphClass(), 'source_id' => $inv->id, 'created_by' => $inv->created_by,
            ]);
            $this->posting->replaceLines($entry, $this->journalLines($inv));
            $number = $this->sequences->next($inv->company_id, Invoice::PREFIX[$inv->kind], (int) $inv->date->format('Y'));
            $entry->forceFill(['number' => $number])->save();
            $this->posting->post($entry, $user, system: true);

            $inv->forceFill(['status' => 'posted', 'number' => $number, 'journal_entry_id' => $entry->id, 'posted_by' => $user->id, 'posted_at' => now()])->saveQuietly();
            AuditLog::record('posted', $inv, ['status' => 'draft'], ['status' => 'posted', 'number' => $number]);

            // A credit/debit note against a credit invoice settles it automatically.
            if ($inv->original && $inv->original->payment === 'credit' && $inv->payment === 'credit') {
                $amount = min(Money::toCents($inv->total), $inv->original->openCents());
                if ($amount > 0) {
                    Allocation::create(['company_id' => $inv->company_id, 'invoice_id' => $inv->original_invoice_id, 'credit_invoice_id' => $inv->id, 'amount' => Money::fromCents($amount), 'created_by' => $user->id]);
                    AuditLog::record('allocated', $inv->original, null, ['credit_note' => $number, 'amount' => Money::fromCents($amount)]);
                }
            }

            return $inv->fresh();
        });
    }

    private function checkCreditLimit(Invoice $inv, User $user): void
    {
        $party = $inv->party;
        $limit = Money::toCents($party->credit_limit);
        if ($inv->kind !== 'sale' || $inv->payment !== 'credit' || $limit <= 0) {
            return;
        }
        $after = $party->balance() + Money::toCents($inv->total);
        if ($after <= $limit) {
            return;
        }
        $msg = __('Credit limit of :p exceeded: balance after this invoice :b, limit :l.', ['p' => $party->name, 'b' => Money::format($after), 'l' => Money::format($limit)]);
        if ($party->credit_limit_action === 'block' && ! $user->can(Perm::CREDIT_LIMIT_OVERRIDE)) {
            throw new AccountingException($msg);
        }
        $this->warnings[] = $msg;
        AuditLog::record('credit_limit', $inv, null, ['balance_after' => Money::fromCents($after), 'limit' => $party->credit_limit]);
    }

    /** The journal lines an invoice generates. */
    public function journalLines(Invoice $inv): array
    {
        $sale = $inv->isSale();
        // +1: party side is debit (sale, purchase return); -1: party side is credit (purchase, sales return)
        $partyDebit = in_array($inv->kind, ['sale', 'purchase_return'], true);
        $dir = $sale ? 'sale' : 'purchase';
        $side = fn (int $cents, bool $debit) => $debit ? ['debit' => Money::fromCents($cents), 'credit' => 0] : ['debit' => 0, 'credit' => Money::fromCents($cents)];

        $lines = [];
        $total = Money::toCents($inv->total);
        $lines[] = ($inv->payment === 'cash'
            ? ['account_id' => $inv->cash_account_id]
            : ['account_id' => $inv->party->account_id, 'party_id' => $inv->party_id])
            + $side($total, $partyDebit) + ['description' => $inv->party->name];

        $vat = []; // tax_code_id => [base, tax]
        foreach ($inv->lines()->with('taxCode')->get() as $l) {
            $net = Money::toCents($l->net);
            $lines[] = ['account_id' => $l->account_id, 'description' => $l->description, 'tax_code_id' => $l->tax_code_id, 'tax_direction' => $l->tax_code_id ? $dir : null] + $side($net, ! $partyDebit);
            if ($l->taxCode && in_array($l->taxCode->type, ['standard', 'reverse_charge'], true)) {
                $vat[$l->tax_code_id] ??= [0, 0, $l->taxCode];
                $vat[$l->tax_code_id][0] += $net;
                $vat[$l->tax_code_id][1] += Money::toCents($l->tax);
            }
        }
        foreach ($vat as $codeId => [$base, $tax, $code]) {
            if ($tax === 0) {
                continue;
            }
            if ($code->type === 'standard') {
                $acc = $sale ? $code->output_account_id : $code->input_account_id;
                if (! $acc) {
                    throw new AccountingException(__('Tax code :c has no VAT account configured.', ['c' => $code->code]));
                }
                $lines[] = ['account_id' => $acc, 'description' => $sale ? __('Output VAT') : __('Input VAT'), 'tax_code_id' => $codeId, 'is_tax_line' => true, 'tax_base' => Money::fromCents($base), 'tax_direction' => $dir] + $side($tax, ! $partyDebit);
            } else { // reverse charge: self-assess output and recover input
                $lines[] = ['account_id' => $code->input_account_id, 'description' => __('Input VAT (reverse charge)'), 'tax_code_id' => $codeId, 'is_tax_line' => true, 'tax_base' => Money::fromCents($base), 'tax_direction' => 'purchase'] + $side($tax, ! $partyDebit);
                $lines[] = ['account_id' => $code->output_account_id, 'description' => __('Output VAT (reverse charge)'), 'tax_code_id' => $codeId, 'is_tax_line' => true, 'tax_base' => Money::fromCents($base), 'tax_direction' => 'sale'] + $side($tax, $partyDebit);
            }
        }

        return $lines;
    }

    /** Cancel a posted invoice by reversing its entry. Not allowed once sent as an e-invoice (§5). */
    public function cancel(Invoice $inv, User $user, string $reason): Invoice
    {
        $this->posting->authorize($user, Perm::INVOICES_CANCEL);
        if (trim($reason) === '') {
            throw new AccountingException(__('A reason is required.'));
        }

        return DB::transaction(function () use ($inv, $user, $reason) {
            $inv = Invoice::whereKey($inv->id)->lockForUpdate()->firstOrFail();
            if ($inv->status !== 'posted') {
                throw new AccountingException(__('Only posted invoices can be cancelled.'));
            }
            if (in_array($inv->einvoice_status, ['sent', 'accepted'], true)) {
                throw new AccountingException(__('This invoice was sent electronically and cannot be cancelled. Issue a credit note instead.'));
            }
            if ($inv->allocations()->whereNotNull('voucher_id')->exists() || $inv->creditAllocations()->exists()) {
                throw new AccountingException(__('Remove the payments allocated to this invoice first.'));
            }
            $inv->allocations()->delete(); // credit-note links to it, if any
            $this->posting->reverse($inv->journalEntry, $user, $reason, null, system: true);
            $inv->status = 'cancelled';
            $inv->save();
            AuditLog::record('cancelled', $inv, ['status' => 'posted'], ['status' => 'cancelled'], $reason);

            return $inv;
        });
    }

    /** Draft credit/debit note pre-filled from an invoice. */
    public function makeReturn(Invoice $inv, User $user): Invoice
    {
        if ($inv->status !== 'posted' || $inv->isReturn()) {
            throw new AccountingException(__('Credit notes are issued from posted invoices.'));
        }
        $return = new Invoice([
            'company_id' => $inv->company_id, 'branch_id' => $inv->branch_id,
            'kind' => $inv->kind === 'sale' ? 'sale_return' : 'purchase_return',
            'payment' => $inv->payment, 'cash_account_id' => $inv->cash_account_id,
            'party_id' => $inv->party_id, 'date' => now()->toDateString(), 'original_invoice_id' => $inv->id,
            'description' => __('Return of :n', ['n' => $inv->number]),
        ]);
        $returnsAccount = $inv->kind === 'sale' ? $inv->company->accounts()->where('code', '4114')->value('id') : null;

        return $this->saveDraft($return, $inv->lines->map(fn ($l) => [
            'account_id' => $returnsAccount ?? $l->account_id, 'description' => $l->description, 'quantity' => $l->quantity,
            'unit' => $l->unit, 'unit_price' => $l->unit_price, 'tax_code_id' => $l->tax_code_id,
        ])->all(), $user);
    }

    /**
     * Allocate a posted voucher to invoices: [invoice_id => amount]. Receipts settle sales invoices
     * (or supplier debit notes); payments settle purchase bills (or customer credit notes, i.e. refunds).
     */
    public function allocate(Voucher $v, array $amounts, User $user, bool $authorized = false): void
    {
        if (! $authorized) {
            $this->posting->authorize($user, Perm::VOUCHERS_POST);
        }
        DB::transaction(function () use ($v, $amounts, $user) {
            $v = Voucher::whereKey($v->id)->lockForUpdate()->firstOrFail();
            if ($v->status !== 'posted' || ! $v->party_id) {
                throw new AccountingException(__('Only posted vouchers of a customer or supplier can be allocated.'));
            }
            $used = $v->allocations()->get()->sum(fn ($a) => Money::toCents($a->amount));
            $allowedKinds = $v->type === 'receipt' ? ['sale', 'purchase_return'] : ['purchase', 'sale_return'];
            foreach ($amounts as $invoiceId => $amount) {
                $cents = Money::toCents($amount);
                if ($cents <= 0) {
                    continue;
                }
                $inv = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
                if ($inv->party_id !== $v->party_id || ! in_array($inv->kind, $allowedKinds, true)) {
                    throw new AccountingException(__('Invoice :n cannot be settled by this voucher.', ['n' => $inv->display_number]));
                }
                if ($cents > $inv->openCents()) {
                    throw new AccountingException(__('Amount exceeds the open balance of :n (:o).', ['n' => $inv->number, 'o' => Money::format($inv->openCents())]));
                }
                $used += $cents;
                if ($used > Money::toCents($v->total)) {
                    throw new AccountingException(__('Allocations exceed the voucher amount.'));
                }
                Allocation::create(['company_id' => $v->company_id, 'invoice_id' => $inv->id, 'voucher_id' => $v->id, 'amount' => Money::fromCents($cents), 'created_by' => $user->id]);
                AuditLog::record('allocated', $inv, null, ['voucher' => $v->number, 'amount' => Money::fromCents($cents)]);
            }
        });
    }

    public function unallocatedCents(Voucher $v): int
    {
        return Money::toCents($v->total) - $v->allocations()->get()->sum(fn ($a) => Money::toCents($a->amount));
    }

    /**
     * Register a payment for an invoice: a voucher linked to the invoice. It is posted right away when the
     * user may post it (and segregation of duties allows); otherwise it waits as a draft, and the
     * allocation happens when it is posted.
     */
    public function registerPayment(Invoice $inv, array $data, User $user, VoucherService $vouchers): Voucher
    {
        if ($inv->status !== 'posted' || $inv->payment !== 'credit') {
            throw new AccountingException(__('Only posted credit invoices can receive payments.'));
        }
        if (Money::toCents($data['amount']) > $inv->openCents()) {
            throw new AccountingException(__('Amount exceeds the open balance of :n (:o).', ['n' => $inv->number, 'o' => Money::format($inv->openCents())]));
        }

        return DB::transaction(function () use ($inv, $data, $user, $vouchers) {
            $receipt = in_array($inv->kind, ['sale', 'purchase_return'], true);
            $v = $vouchers->saveDraft(new Voucher([
                'company_id' => $inv->company_id, 'branch_id' => $inv->branch_id, 'type' => $receipt ? 'receipt' : 'payment',
                'date' => $data['date'], 'cash_account_id' => $data['cash_account_id'], 'counter_account_id' => $inv->party->account_id,
                'party_id' => $inv->party_id, 'pays_invoice_id' => $inv->id, 'party_name' => $inv->party->name, 'amount' => $data['amount'],
                'tax_code_id' => $inv->company->taxCodes()->where('code', 'OS')->value('id'),
                'payment_method' => $data['payment_method'] ?? 'cash', 'cheque_number' => $data['cheque_number'] ?? null,
                'description' => __('Payment of :n', ['n' => $inv->number]),
            ]), $user);
            if ($user->can(Perm::VOUCHERS_POST) && ! $inv->company->segregation_of_duties) {
                $v = $vouchers->post($v, $user);
            }

            return $v;
        });
    }

    /** Called by VoucherService after posting: settle the linked invoice. */
    public function allocateLinked(Voucher $v, User $user): void
    {
        $inv = $v->paysInvoice;
        if (! $inv) {
            return;
        }
        $amount = min($inv->openCents(), $this->unallocatedCents($v));
        if ($amount > 0) {
            $this->allocate($v, [$inv->id => Money::fromCents($amount)], $user, authorized: true);
        }
    }
}
