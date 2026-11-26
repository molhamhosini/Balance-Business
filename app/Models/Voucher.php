<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Voucher extends Model
{
    public const TYPES = ['receipt' => 'سند قبض', 'payment' => 'سند صرف'];

    public const STATUSES = ['draft' => 'مسودة', 'posted' => 'مرحّل', 'cancelled' => 'ملغى'];

    public const CHEQUE_STATUSES = ['in_box' => 'بالصندوق', 'deposited' => 'مودع', 'issued' => 'صادر', 'cleared' => 'مصروف من البنك', 'returned' => 'مرتجع'];

    public const METHODS = ['cash' => 'نقداً', 'bank_transfer' => 'تحويل بنكي', 'cheque' => 'شيك', 'card' => 'بطاقة'];

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'cheque_due_date' => 'date', 'posted_at' => 'datetime', 'amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(function (Voucher $v) {
            if (($v->getOriginal('status') ?? 'draft') !== 'draft' && array_diff(array_keys($v->getDirty()), ['status', 'cheque_status', 'cheque_entry_id', 'updated_at'])) {
                throw new AccountingException(__('A posted voucher cannot be modified. Cancel it with a reversing entry.'));
            }
        });
        static::deleting(function (Voucher $v) {
            if (($v->getOriginal('status') ?? 'draft') !== 'draft') {
                throw new AccountingException(__('A posted voucher cannot be deleted.'));
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(Account::class, 'cash_account_id'); }
    public function counterAccount(): BelongsTo { return $this->belongsTo(Account::class, 'counter_account_id'); }
    public function taxCode(): BelongsTo { return $this->belongsTo(TaxCode::class); }
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Allocation::class); }
    public function paysInvoice(): BelongsTo { return $this->belongsTo(Invoice::class, 'pays_invoice_id'); }
    public function chequeEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'cheque_entry_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(CostCenter::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function poster(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function attachments(): MorphMany { return $this->morphMany(Attachment::class, 'attachable'); }

    public function getDisplayNumberAttribute(): string
    {
        return $this->number ?? __('Draft').' #'.$this->id;
    }
}
