<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Product / service card. Stock items are valued at weighted average cost, perpetually. */
class Item extends Model
{
    public const TYPES = ['stock' => 'صنف مخزني', 'service' => 'خدمة'];

    protected $guarded = ['id'];

    protected $casts = ['sale_price' => 'decimal:4', 'qty_on_hand' => 'decimal:3', 'avg_cost' => 'decimal:6', 'stock_value' => 'decimal:2', 'is_active' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function units(): HasMany { return $this->hasMany(ItemUnit::class); }
    public function moves(): HasMany { return $this->hasMany(StockMove::class); }
    public function taxCode(): BelongsTo { return $this->belongsTo(TaxCode::class); }
    public function inventoryAccount(): BelongsTo { return $this->belongsTo(Account::class, 'inventory_account_id'); }
    public function cogsAccount(): BelongsTo { return $this->belongsTo(Account::class, 'cogs_account_id'); }
    public function salesAccount(): BelongsTo { return $this->belongsTo(Account::class, 'sales_account_id'); }
    public function purchaseAccount(): BelongsTo { return $this->belongsTo(Account::class, 'purchase_account_id'); }

    public function isStock(): bool { return $this->type === 'stock'; }

    public function getNameAttribute(): string { return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar; }
    public function getLabelAttribute(): string { return $this->code.' — '.$this->name; }

    /** Quantity in a warehouse (base units) as of a date. */
    public function qtyIn(int $warehouseId, ?string $asOf = null): string
    {
        return (string) $this->moves()->where('warehouse_id', $warehouseId)->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))->sum('quantity');
    }

    /** Factor of a unit name (base unit = 1). */
    public function factorOf(?string $unit): string
    {
        if (! $unit || $unit === $this->unit) {
            return '1';
        }

        return (string) ($this->units->firstWhere('name', $unit)?->factor ?? 1);
    }

    public static function nextCode(int $companyId): string
    {
        $n = static::where('company_id', $companyId)->where('code', 'like', 'P%')->count() + 1;
        do {
            $code = 'P'.str_pad((string) $n++, 5, '0', STR_PAD_LEFT);
        } while (static::where('company_id', $companyId)->where('code', $code)->exists());

        return $code;
    }

    public function valueCents(): int { return Money::toCents($this->stock_value); }
}
