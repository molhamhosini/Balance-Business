<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'net' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];

    protected static function booted(): void
    {
        $guard = function (InvoiceLine $l) {
            $inv = Invoice::find($l->invoice_id);
            if ($inv && $inv->status !== 'draft') {
                throw new AccountingException(__('Lines of a posted invoice cannot be changed.'));
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function taxCode(): BelongsTo { return $this->belongsTo(TaxCode::class); }
}
