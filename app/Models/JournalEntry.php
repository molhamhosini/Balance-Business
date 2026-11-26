<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    public const STATUSES = ['draft' => 'مسودة', 'posted' => 'مرحّل', 'reversed' => 'معكوس'];

    public const TYPES = ['manual' => 'قيد يدوي', 'voucher' => 'سند', 'invoice' => 'فاتورة', 'reversal' => 'قيد عكسي', 'opening' => 'قيد افتتاحي', 'closing' => 'قيد إقفال'];

    /** Only these columns may change once an entry has left draft. */
    private const MUTABLE_AFTER_POST = ['status', 'reversed_by_id', 'updated_at'];

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'posted_at' => 'datetime', 'total' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $e) {
            if (($e->getOriginal('status') ?? 'draft') !== 'draft') {
                $changed = array_diff(array_keys($e->getDirty()), self::MUTABLE_AFTER_POST);
                if ($changed) {
                    throw new AccountingException(__('A posted entry cannot be modified. Reverse it instead.'));
                }
            }
        });
        static::deleting(function (JournalEntry $e) {
            if (($e->getOriginal('status') ?? 'draft') !== 'draft') {
                throw new AccountingException(__('A posted entry cannot be deleted. Reverse it instead.'));
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function period(): BelongsTo { return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id'); }
    public function lines(): HasMany { return $this->hasMany(JournalLine::class)->orderBy('sort')->orderBy('id'); }
    public function source(): MorphTo { return $this->morphTo(); }
    public function reverses(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'reverses_id'); }
    public function reversedBy(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'reversed_by_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function poster(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }

    /** Entries that count in reports: posted, including originals that were later reversed. */
    public function scopeEffective(Builder $q): Builder
    {
        return $q->whereIn('status', ['posted', 'reversed']);
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isAutomatic(): bool { return $this->source_type !== null; }

    public function getDisplayNumberAttribute(): string
    {
        return $this->number ?? __('Draft').' #'.$this->id;
    }
}
