<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Matches a payment voucher or a credit/debit note against an invoice. Not an accounting entry. */
class Allocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function voucher(): BelongsTo { return $this->belongsTo(Voucher::class); }
    public function creditInvoice(): BelongsTo { return $this->belongsTo(Invoice::class, 'credit_invoice_id'); }
}
