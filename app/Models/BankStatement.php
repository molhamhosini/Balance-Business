<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatement extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['date_from' => 'date', 'date_to' => 'date', 'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function lines(): HasMany { return $this->hasMany(BankStatementLine::class)->orderBy('date')->orderBy('id'); }

    public function getLabelAttribute(): string { return $this->account->label.' · '.$this->date_from->format('Y-m-d').' → '.$this->date_to->format('Y-m-d'); }
}
