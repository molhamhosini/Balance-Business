<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockDocument;
use App\Models\StockMove;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Perm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Perpetual inventory at weighted average cost (one average per item for the company).
 * Every move updates quantity, value and the journal at once. Quantities are in base units
 * with 3 decimals (mil-units); values are fils. Back-dated moves take the average current at posting.
 */
class InventoryService
{
    public function __construct(private PostingService $posting, private SequenceService $sequences) {}

    public static function milli(string|int|float|null $qty): int
    {
        $v = trim(str_replace(',', '', (string) ($qty ?? '0')));
        $neg = str_starts_with($v, '-');
        [$i, $f] = array_pad(explode('.', ltrim($v, '+-'), 2), 2, '');
        $n = (int) ($i ?: '0') * 1000 + (int) str_pad(substr($f, 0, 3), 3, '0');

        return $neg ? -$n : $n;
    }

    public static function qty(int $milli): string
    {
        $s = $milli < 0 ? '-' : '';
        $m = abs($milli);

        return $s.intdiv($m, 1000).'.'.str_pad((string) ($m % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** Base quantity (milli) of qty × factor, half-up. */
    public static function baseMilli(string|float $qty, string|float $factor): int
    {
        $q = self::milli((string) $qty);
        $f = (int) round(((float) $factor) * 1000000); // factor with 6 decimals

        return intdiv(abs($q) * $f + 500000, 1000000) * ($q < 0 ? -1 : 1);
    }

    private function lockItem(Item $item): Item
    {
        $item = Item::whereKey($item->id)->lockForUpdate()->firstOrFail();
        if (! $item->isStock()) {
            throw new AccountingException(__(':i is a service, not a stock item.', ['i' => $item->label]));
        }

        return $item;
    }

    /** Stock in. $valueCents is the total cost of the quantity. */
    public function receive(Item $item, Warehouse $wh, int $milli, int $valueCents, string $date, ?Model $source = null, ?int $entryId = null, ?string $desc = null): StockMove
    {
        if ($milli <= 0) {
            throw new AccountingException(__('Quantity must be greater than zero.'));
        }
        $item = $this->lockItem($item);

        return $this->write($item, $wh, $milli, $valueCents, $date, $source, $entryId, $desc);
    }

    /**
     * Stock out at the average cost (or at $valueCents when given, e.g. a purchase return at its bill price).
     * Returns the move; its value is negative.
     */
    public function issue(Item $item, Warehouse $wh, int $milli, string $date, ?Model $source = null, ?int $entryId = null, ?string $desc = null, ?int $valueCents = null): StockMove
    {
        if ($milli <= 0) {
            throw new AccountingException(__('Quantity must be greater than zero.'));
        }
        $item = $this->lockItem($item);
        $inWh = self::milli($item->qtyIn($wh->id));
        if ($inWh < $milli && ! $item->company->allow_negative_stock) {
            throw new AccountingException(__('Not enough stock of :i in :w: available :a, needed :n.', ['i' => $item->label, 'w' => $wh->name, 'a' => self::qty($inWh), 'n' => self::qty($milli)]));
        }
        $totalQty = self::milli($item->qty_on_hand);
        $totalValue = Money::toCents($item->stock_value);
        if ($valueCents === null) {
            // issuing everything takes the whole value, so no rounding residue stays behind
            $valueCents = $milli === $totalQty ? $totalValue : (int) round($milli * (float) $item->avg_cost / 10);
        }

        return $this->write($item, $wh, -$milli, -$valueCents, $date, $source, $entryId, $desc);
    }

    private function write(Item $item, Warehouse $wh, int $milli, int $valueCents, string $date, ?Model $source, ?int $entryId, ?string $desc): StockMove
    {
        if ($wh->company_id !== $item->company_id) {
            throw new AccountingException(__('Warehouse of another company.'));
        }
        $qtyAfter = self::milli($item->qty_on_hand) + $milli;
        $valueAfter = Money::toCents($item->stock_value) + $valueCents;
        // avg cost per base unit in AED with 6 decimals: fils / milli-units
        $avg = $qtyAfter > 0 ? round($valueAfter * 10 / $qtyAfter, 6) : ($milli > 0 ? round($valueCents * 10 / $milli, 6) : (float) $item->avg_cost);
        $move = StockMove::create([
            'company_id' => $item->company_id, 'item_id' => $item->id, 'warehouse_id' => $wh->id, 'date' => $date,
            'quantity' => self::qty($milli), 'unit_cost' => $milli !== 0 ? number_format(abs($valueCents) * 10 / abs($milli), 6, '.', '') : '0',
            'value' => Money::fromCents($valueCents), 'qty_after' => self::qty($qtyAfter), 'avg_cost_after' => number_format($avg, 6, '.', ''),
            'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(), 'journal_entry_id' => $entryId, 'description' => $desc,
        ]);
        $item->forceFill(['qty_on_hand' => self::qty($qtyAfter), 'stock_value' => Money::fromCents($valueAfter), 'avg_cost' => number_format($avg, 6, '.', '')])->save();

        return $move;
    }

    /** Undo the moves of a document (on cancellation): opposite moves at the same value. */
    public function reverseMoves(Model $source, string $date, ?int $entryId = null): void
    {
        foreach (StockMove::where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->orderBy('id')->get() as $m) {
            $item = Item::whereKey($m->item_id)->lockForUpdate()->firstOrFail();
            $this->write($item, $m->warehouse, -self::milli($m->quantity), -Money::toCents($m->value), $date, $source, $entryId, __('Reversal').' '.($m->description ?? ''));
        }
    }

    // ---------- Stock documents ----------

    public function saveDocument(StockDocument $d, array $lines, User $user): StockDocument
    {
        $this->posting->authorize($user, Perm::INVENTORY_MANAGE);
        if ($d->exists && $d->getOriginal('status') !== 'draft') {
            throw new AccountingException(__('A posted document cannot be modified.'));
        }
        if (! $lines) {
            throw new AccountingException(__('Add at least one line.'));
        }
        if ($d->type === 'transfer' && (! $d->to_warehouse_id || $d->to_warehouse_id === $d->warehouse_id)) {
            throw new AccountingException(__('Choose a different destination warehouse.'));
        }
        if ($d->type === 'landed_cost' && Money::toCents($d->amount) <= 0) {
            throw new AccountingException(__('Enter the landed cost amount.'));
        }

        return DB::transaction(function () use ($d, $lines, $user) {
            $d->created_by ??= $user->id;
            $d->status = 'draft';
            $d->save();
            $d->lines()->delete();
            foreach (array_values($lines) as $i => $l) {
                $d->lines()->create([
                    'item_id' => $l['item_id'] ?? null, 'quantity' => $l['quantity'] ?? 0, 'unit_cost' => $l['unit_cost'] ?? null,
                    'invoice_id' => $l['invoice_id'] ?? null, 'sort' => $i,
                ]);
            }
            AuditLog::record($d->wasRecentlyCreated ? 'created' : 'updated', $d, null, $d->only(['type', 'date', 'warehouse_id']));

            return $d->fresh('lines');
        });
    }

    public function postDocument(StockDocument $d, User $user): StockDocument
    {
        $this->posting->authorize($user, Perm::INVENTORY_POST);

        return DB::transaction(function () use ($d, $user) {
            $d = StockDocument::whereKey($d->id)->lockForUpdate()->with('lines.item')->firstOrFail();
            if ($d->status !== 'draft') {
                throw new AccountingException(__('Only drafts can be posted.'));
            }
            $this->posting->periodFor($d->company_id, $d->date->toDateString()); // fail early on a closed period
            $number = $this->sequences->next($d->company_id, StockDocument::PREFIX[$d->type], (int) $d->date->format('Y'));
            $date = $d->date->toDateString();
            $desc = __(StockDocument::TYPES[$d->type]).' '.$number.($d->description ? ' — '.$d->description : '');
            $journal = [];
            $add = function (int $accountId, int $cents, string $text) use (&$journal) {
                if ($cents === 0) {
                    return;
                }
                $journal[] = ['account_id' => $accountId, 'debit' => $cents > 0 ? Money::fromCents($cents) : 0, 'credit' => $cents < 0 ? Money::fromCents(-$cents) : 0, 'description' => $text];
            };
            $invAcc = fn (Item $i) => $i->inventory_account_id ?? $d->company->systemAccount('inventory')?->id;
            $variance = $d->company->systemAccount('inventory_variance')?->id;
            // Periodic inventory: the inventory account moves only with the opening stock and the closing
            // adjustment; consumption is taken out of purchases, count differences and landed costs stay in cost of sales.
            $periodic = $d->company->isPeriodic();
            $purchases = $d->company->systemAccount('cogs')?->id;

            switch ($d->type) {
                case 'opening':
                case 'issue':
                    if (! $d->counter_account_id) {
                        throw new AccountingException($d->type === 'opening' ? __('Choose the opening balance account (equity).') : __('Choose the expense / cost account.'));
                    }
                    foreach ($d->lines as $l) {
                        $q = self::milli($l->quantity);
                        if ($d->type === 'opening') {
                            $cents = (int) round($q * (float) $l->unit_cost / 10);
                            $this->receive($l->item, $d->warehouse, $q, $cents, $date, $d, null, $desc);
                        } else {
                            $cents = Money::toCents($this->issue($l->item, $d->warehouse, $q, $date, $d, null, $desc)->value); // negative
                        }
                        $add($periodic && $d->type === 'issue' ? $purchases : $invAcc($l->item), $cents, $l->item->name_ar);
                        $add($d->counter_account_id, -$cents, $l->item->name_ar);
                    }
                    break;
                case 'transfer':
                    foreach ($d->lines as $l) {
                        $q = self::milli($l->quantity);
                        $out = $this->issue($l->item, $d->warehouse, $q, $date, $d, null, $desc);
                        $this->receive($l->item, $d->toWarehouse, $q, -Money::toCents($out->value), $date, $d, null, $desc);
                    }
                    break; // same company and account: no journal effect
                case 'count':
                    foreach ($d->lines as $l) {
                        $book = self::milli($l->item->qtyIn($d->warehouse_id));
                        $counted = self::milli($l->quantity);
                        $l->forceFill(['system_quantity' => self::qty($book)])->save();
                        $diff = $counted - $book;
                        if ($diff > 0) {
                            $cents = (int) round($diff * (float) $l->item->avg_cost / 10);
                            $this->receive($l->item, $d->warehouse, $diff, $cents, $date, $d, null, __('Count surplus'));
                        } elseif ($diff < 0) {
                            $cents = Money::toCents($this->issue($l->item, $d->warehouse, -$diff, $date, $d, null, __('Count shortage'))->value);
                        } else {
                            continue;
                        }
                        if ($periodic) {
                            continue; // reflected in the closing stock value
                        }
                        $add($invAcc($l->item), $cents, $l->item->name_ar);
                        $add($d->counter_account_id ?? $variance, -$cents, __('Inventory count difference').' — '.$l->item->name_ar);
                    }
                    break;
                case 'landed_cost':
                    $total = Money::toCents($d->amount);
                    if (! $d->counter_account_id) {
                        throw new AccountingException(__('Choose the account to credit (freight / customs payable or clearing).'));
                    }
                    // allocate over the stock lines of the selected bills, by value or by quantity
                    $targets = [];
                    foreach ($d->lines as $l) {
                        $bill = $l->invoice;
                        if (! $bill || $bill->company_id !== $d->company_id || $bill->kind !== 'purchase' || $bill->status !== 'posted') {
                            throw new AccountingException(__('Landed costs are charged to posted purchase bills.'));
                        }
                        foreach ($bill->lines()->whereNotNull('item_id')->with('item')->get() as $bl) {
                            if ($bl->item?->isStock()) {
                                $targets[] = [$bl, $d->allocation === 'quantity' ? self::baseMilli($bl->quantity, $bl->unit_factor) : Money::toCents($bl->net) * $bill->fx_rate];
                            }
                        }
                    }
                    $weight = array_sum(array_column($targets, 1));
                    if (! $targets || $weight <= 0) {
                        throw new AccountingException(__('The selected bills have no stock items.'));
                    }
                    $left = $total;
                    foreach ($targets as $i => [$bl, $w]) {
                        $share = $i === array_key_last($targets) ? $left : (int) round($total * $w / $weight);
                        $left -= $share;
                        if ($share === 0) {
                            continue;
                        }
                        $item = Item::whereKey($bl->item_id)->lockForUpdate()->firstOrFail();
                        if (self::milli($item->qty_on_hand) <= 0) { // nothing left in stock: the cost belongs to cost of sales
                            $add($item->cogs_account_id ?? $d->company->systemAccount('cogs')->id, $share, __('Landed cost').' — '.$item->name_ar);

                            continue;
                        }
                        // value only, no quantity: raises the average cost of what is in stock
                        $this->write($item, $bl->warehouse ?? $d->warehouse, 0, $share, $date, $d, null, __('Landed cost').' '.$bl->invoice->number);
                        $add($periodic ? ($d->company->systemAccount('freight_in')?->id ?? $purchases) : $invAcc($item), $share, __('Landed cost').' — '.$item->name_ar);
                    }
                    $add($d->counter_account_id, -$total, $desc);
                    break;
                case 'closing':
                    // Periodic inventory, end of period: set each inventory account to the value of the stock
                    // on hand (weighted average) against cost of sales. COGS = opening + purchases − closing.
                    if (! $periodic) {
                        throw new AccountingException(__('The closing stock adjustment is used with the periodic inventory system.'));
                    }
                    foreach ($this->closingAdjustments($d->company, $date) as $accountId => $diff) {
                        $add($accountId, $diff, __('Closing stock').' '.$date);
                        $add($purchases, -$diff, __('Change in inventory').' '.$date);
                    }
                    break;
            }

            $entryId = null;
            if ($journal) {
                $entry = $this->posting->postGenerated($d, 'stock', StockDocument::PREFIX[$d->type], ['company_id' => $d->company_id, 'branch_id' => $d->branch_id, 'date' => $date, 'description' => $desc], $journal, $user, $number);
                $entryId = $entry->id;
                StockMove::where('source_type', $d->getMorphClass())->where('source_id', $d->id)->toBase()->update(['journal_entry_id' => $entryId]);
            }
            $d->forceFill(['status' => 'posted', 'number' => $number, 'journal_entry_id' => $entryId, 'posted_by' => $user->id])->saveQuietly();
            AuditLog::record('posted', $d, ['status' => 'draft'], ['status' => 'posted', 'number' => $number]);

            return $d;
        });
    }

    /**
     * Per inventory account: stock value on hand at $date minus the account's book balance, in fils.
     *
     * @return array<int, int>
     */
    public function closingAdjustments(\App\Models\Company $company, string $date): array
    {
        $fallback = $company->systemAccount('inventory')?->id;
        $stock = DB::table('stock_moves as m')->join('items as i', 'i.id', '=', 'm.item_id')
            ->where('m.company_id', $company->id)->where('m.date', '<=', $date)
            ->groupBy('i.inventory_account_id')->selectRaw('i.inventory_account_id acc, SUM(m.value) v')->get();
        $values = [];
        foreach ($stock as $r) {
            $acc = $r->acc ?? $fallback;
            $values[$acc] = ($values[$acc] ?? 0) + Money::toCents((string) $r->v);
        }
        $accounts = array_unique(array_filter(array_merge(array_keys($values), [$fallback], Item::where('company_id', $company->id)->whereNotNull('inventory_account_id')->pluck('inventory_account_id')->all())));
        $sums = app(ReportService::class)->sums($company, null, $date);
        $out = [];
        foreach ($accounts as $acc) {
            $diff = ($values[$acc] ?? 0) - (($sums[$acc]['d'] ?? 0) - ($sums[$acc]['c'] ?? 0));
            if ($diff !== 0) {
                $out[$acc] = $diff;
            }
        }

        return $out;
    }

    /** Periodic inventory: create and post the closing stock adjustment for a date. */
    public function postClosing(\App\Models\Company $company, string $date, int $branchId, User $user): StockDocument
    {
        $d = StockDocument::create(['company_id' => $company->id, 'branch_id' => $branchId, 'type' => 'closing', 'date' => $date,
            'description' => __('Closing stock adjustment'), 'status' => 'draft', 'created_by' => $user->id]);

        return $this->postDocument($d, $user);
    }

    public function cancelDocument(StockDocument $d, User $user, string $reason): StockDocument
    {
        $this->posting->authorize($user, Perm::INVENTORY_POST);
        if (trim($reason) === '') {
            throw new AccountingException(__('A reason is required.'));
        }

        return DB::transaction(function () use ($d, $user, $reason) {
            if ($d->status !== 'posted') {
                throw new AccountingException(__('Only posted documents can be cancelled.'));
            }
            $rev = $d->journalEntry ? $this->posting->reverse($d->journalEntry, $user, $reason, null, system: true) : null;
            $this->reverseMoves($d, now()->toDateString(), $rev?->id);
            $d->status = 'cancelled';
            $d->save();
            AuditLog::record('cancelled', $d, ['status' => 'posted'], ['status' => 'cancelled'], $reason);

            return $d;
        });
    }
}
