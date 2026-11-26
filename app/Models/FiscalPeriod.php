<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPeriod extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function fiscalYear(): BelongsTo { return $this->belongsTo(FiscalYear::class); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }

    public function isOpen(): bool { return $this->status === 'open'; }

    public function getLabelAttribute(): string
    {
        return $this->start_date->format('Y-m');
    }
}
