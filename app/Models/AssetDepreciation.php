<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDepreciation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['period_end' => 'date', 'amount' => 'decimal:2'];

    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
    public function run(): BelongsTo { return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id'); }
}
