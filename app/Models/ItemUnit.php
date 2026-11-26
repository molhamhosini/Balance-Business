<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemUnit extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['factor' => 'decimal:6', 'sale_price' => 'decimal:4'];

    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
}
