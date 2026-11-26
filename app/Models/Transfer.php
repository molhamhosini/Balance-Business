<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Transfer between cash boxes and bank accounts. */
class Transfer extends Model
{
    public const STATUSES = ['draft' => 'مسودة', 'posted' => 'مرحّل', 'cancelled' => 'ملغى'];

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(function (Transfer $t) {
            if (($t->getOriginal('status') ?? 'draft') !== 'draft' && array_diff(array_keys($t->getDirty()), ['status', 'updated_at'])) {
                throw new AccountingException(__('A posted document cannot be modified.'));
            }
        });
        static::deleting(function (Transfer $t) {
            if ($t->getOriginal('status') !== 'draft') {
                throw new AccountingException(__('A posted document cannot be deleted.'));
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function fromAccount(): BelongsTo { return $this->belongsTo(Account::class, 'from_account_id'); }
    public function toAccount(): BelongsTo { return $this->belongsTo(Account::class, 'to_account_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function getDisplayNumberAttribute(): string { return $this->number ?? __('Draft').' #'.$this->id; }
}
