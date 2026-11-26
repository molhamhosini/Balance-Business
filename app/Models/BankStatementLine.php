<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function statement(): BelongsTo { return $this->belongsTo(BankStatement::class, 'bank_statement_id'); }
    public function journalLine(): BelongsTo { return $this->belongsTo(JournalLine::class); }
}
