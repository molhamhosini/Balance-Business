<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fixed asset card, depreciated straight-line monthly. */
class Asset extends Model
{
    public const STATUSES = ['running' => 'قيد الإهلاك', 'fully_depreciated' => 'مهلك بالكامل', 'disposed' => 'مستبعد'];

    protected $guarded = ['id'];

    protected $casts = [
        'purchase_date' => 'date', 'depreciation_start' => 'date', 'disposal_date' => 'date',
        'cost' => 'decimal:2', 'salvage_value' => 'decimal:2', 'opening_accumulated' => 'decimal:2', 'disposal_proceeds' => 'decimal:2',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function category(): BelongsTo { return $this->belongsTo(AssetCategory::class, 'asset_category_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(CostCenter::class); }
    public function depreciations(): HasMany { return $this->hasMany(AssetDepreciation::class)->orderBy('period_end'); }
    public function disposalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'disposal_entry_id'); }

    public function getNameAttribute(): string { return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar; }
    public function getLabelAttribute(): string { return $this->code.' — '.$this->name; }

    public function depreciableCents(): int { return Money::toCents($this->cost) - Money::toCents($this->salvage_value); }

    public function accumulatedCents(?string $asOf = null): int
    {
        return Money::toCents($this->opening_accumulated)
            + $this->depreciations()->when($asOf, fn ($q) => $q->where('period_end', '<=', $asOf))->get()->sum(fn ($d) => Money::toCents($d->amount));
    }

    public function bookValueCents(?string $asOf = null): int { return Money::toCents($this->cost) - $this->accumulatedCents($asOf); }

    public function monthlyCents(): int { return intdiv($this->depreciableCents() + intdiv($this->useful_life_months, 2), max(1, $this->useful_life_months)); }
}
