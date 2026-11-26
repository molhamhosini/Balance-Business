<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Opening stock, issue (consumption), warehouse transfer, physical count, landed cost. */
class StockDocument extends Model
{
    public const TYPES = [
        'opening' => 'مخزون افتتاحي',
        'issue' => 'صرف مخزني',
        'transfer' => 'تحويل بين المستودعات',
        'count' => 'جرد فعلي وتسوية',
        'landed_cost' => 'تكاليف الشحن والجمارك',
    ];

    public const PREFIX = ['opening' => 'OST', 'issue' => 'ISS', 'transfer' => 'STR', 'count' => 'ADJ', 'landed_cost' => 'LC'];

    public const STATUSES = ['draft' => 'مسودة', 'posted' => 'مرحّل', 'cancelled' => 'ملغى'];

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(function (StockDocument $d) {
            if (($d->getOriginal('status') ?? 'draft') !== 'draft' && array_diff(array_keys($d->getDirty()), ['status', 'updated_at'])) {
                throw new AccountingException(__('A posted document cannot be modified.'));
            }
        });
        static::deleting(function (StockDocument $d) {
            if ($d->getOriginal('status') !== 'draft') {
                throw new AccountingException(__('A posted document cannot be deleted.'));
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function toWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'to_warehouse_id'); }
    public function counterAccount(): BelongsTo { return $this->belongsTo(Account::class, 'counter_account_id'); }
    public function lines(): HasMany { return $this->hasMany(StockDocumentLine::class)->orderBy('sort')->orderBy('id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function getDisplayNumberAttribute(): string { return $this->number ?? __('Draft').' #'.$this->id; }
}
