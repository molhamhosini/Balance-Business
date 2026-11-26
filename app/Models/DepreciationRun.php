<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepreciationRun extends Model
{
    protected $guarded = ['id'];

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Company::class); }

    protected $casts = ['period_end' => 'date', 'total' => 'decimal:2'];

    public function lines(): HasMany { return $this->hasMany(AssetDepreciation::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
