<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDocumentLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:6', 'system_quantity' => 'decimal:3'];

    public function document(): BelongsTo { return $this->belongsTo(StockDocument::class, 'stock_document_id'); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
