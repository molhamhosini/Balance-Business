<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    public const KINDS = [
        'sale' => 'فاتورة بيع',
        'sale_return' => 'إشعار دائن / مرتجع مبيعات',
        'purchase' => 'فاتورة شراء',
        'purchase_return' => 'إشعار مدين / مرتجع مشتريات',
        'quotation' => 'عرض سعر',
    ];

    public const PREFIX = ['sale' => 'INV', 'sale_return' => 'CN', 'purchase' => 'BILL', 'purchase_return' => 'DN', 'quotation' => 'QT'];

    public const STATUSES = ['draft' => 'مسودة', 'posted' => 'مرحّل', 'cancelled' => 'ملغى', 'sent' => 'مُرسَل', 'converted' => 'محوّل إلى فاتورة'];

    /** Quotation states: draft -> sent -> converted (to a sales invoice), or cancelled. */
    public const QUOTATION_STATUSES = ['draft' => 'مسودة', 'sent' => 'مُرسَل', 'converted' => 'محوّل إلى فاتورة', 'cancelled' => 'ملغى'];

    public const EINVOICE = ['not_sent' => 'لم تُرسل', 'sent' => 'مرسلة', 'accepted' => 'مقبولة', 'rejected' => 'مرفوضة'];

    private const MUTABLE_AFTER_POST = ['status', 'einvoice_status', 'einvoice_reference', 'einvoice_reason', 'einvoice_at', 'updated_at'];

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date', 'due_date' => 'date', 'posted_at' => 'datetime', 'einvoice_at' => 'datetime',
        'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'fx_rate' => 'decimal:6', 'base_total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Invoice $i) => $i->uuid ??= (string) Str::uuid());
        static::updating(function (Invoice $i) {
            $open = $i->kind === 'quotation' ? ['draft', 'sent'] : ['draft'];
            if (! in_array($i->getOriginal('status') ?? 'draft', $open, true) && array_diff(array_keys($i->getDirty()), self::MUTABLE_AFTER_POST)) {
                throw new AccountingException(__('A posted invoice cannot be modified. Issue a credit note instead.'));
            }
        });
        static::deleting(function (Invoice $i) {
            if (($i->getOriginal('status') ?? 'draft') !== 'draft') {
                throw new AccountingException(__('A posted invoice cannot be deleted.'));
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(Account::class, 'cash_account_id'); }
    public function lines(): HasMany { return $this->hasMany(InvoiceLine::class)->orderBy('sort')->orderBy('id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function original(): BelongsTo { return $this->belongsTo(Invoice::class, 'original_invoice_id'); }
    public function returns(): HasMany { return $this->hasMany(Invoice::class, 'original_invoice_id'); }
    public function quotation(): BelongsTo { return $this->belongsTo(Invoice::class, 'quotation_id'); }
    public function convertedInvoices(): HasMany { return $this->hasMany(Invoice::class, 'quotation_id'); }
    public function allocations(): HasMany { return $this->hasMany(Allocation::class); }
    public function creditAllocations(): HasMany { return $this->hasMany(Allocation::class, 'credit_invoice_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function poster(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }

    public function isSale(): bool { return in_array($this->kind, ['sale', 'sale_return', 'quotation'], true); }
    public function isQuotation(): bool { return $this->kind === 'quotation'; }

    /** Can go to the customer by email / WhatsApp: a posted sales document, or a quotation. */
    public function isSendable(): bool
    {
        return $this->isQuotation() ? $this->status !== 'cancelled' : ($this->status === 'posted' && $this->isSale());
    }
    public function isReturn(): bool { return in_array($this->kind, ['sale_return', 'purchase_return'], true); }
    public function partyType(): string { return $this->isSale() ? 'customer' : 'supplier'; }

    public function getDisplayNumberAttribute(): string
    {
        return $this->number ?? __('Draft').' #'.$this->id;
    }

    /** Settled amount in fils (payments + credit notes applied to this invoice). */
    public function settledCents(): int
    {
        return $this->allocations()->get()->sum(fn ($a) => Money::toCents($a->amount));
    }

    /** For a credit/debit note: amount already applied to invoices. */
    public function appliedCents(): int
    {
        return $this->creditAllocations()->get()->sum(fn ($a) => Money::toCents($a->amount));
    }

    /** Total in AED (what the party owes in the books); equals total for AED documents. */
    public function baseTotalCents(): int
    {
        return ($this->currency ?? 'AED') === 'AED' || Money::toCents($this->base_total) === 0 ? Money::toCents($this->total) : Money::toCents($this->base_total);
    }

    public function openCents(): int
    {
        if ($this->status !== 'posted' || $this->payment === 'cash') {
            return 0;
        }

        return $this->baseTotalCents() - ($this->isReturn() ? $this->appliedCents() : $this->settledCents());
    }

    public function paymentState(): string
    {
        if ($this->status !== 'posted') {
            return $this->status;
        }
        if ($this->payment === 'cash') {
            return 'paid';
        }
        $open = $this->openCents();

        return $open <= 0 ? 'paid' : ($open < $this->baseTotalCents() ? 'partial' : 'not_paid');
    }
}
