<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cost center / project: a tree independent of the chart of accounts, set on journal lines. */
class CostCenter extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function parent(): BelongsTo { return $this->belongsTo(CostCenter::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(CostCenter::class, 'parent_id'); }

    public function getNameAttribute(): string { return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar; }
    public function getLabelAttribute(): string { return $this->code.' — '.$this->name; }

    /** This center and all below it. */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier) {
            $frontier = static::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
