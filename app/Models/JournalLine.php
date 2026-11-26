<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'tax_base' => 'decimal:2', 'is_tax_line' => 'boolean'];

    protected static function booted(): void
    {
        $guard = function (JournalLine $l) {
            $entry = JournalEntry::find($l->journal_entry_id);
            if ($entry && $entry->status !== 'draft') {
                throw new AccountingException(__('Lines of a posted entry cannot be changed.'));
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function entry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function taxCode(): BelongsTo { return $this->belongsTo(TaxCode::class); }
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
}
