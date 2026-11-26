<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Immutable stock ledger row. Corrections are new moves. */
class StockMove extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'quantity' => 'decimal:3', 'unit_cost' => 'decimal:6', 'value' => 'decimal:2', 'qty_after' => 'decimal:3', 'avg_cost_after' => 'decimal:6'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new AccountingException(__('Stock moves cannot be changed.')));
        static::deleting(fn () => throw new AccountingException(__('Stock moves cannot be deleted.')));
    }

    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function source(): MorphTo { return $this->morphTo(); }
}
