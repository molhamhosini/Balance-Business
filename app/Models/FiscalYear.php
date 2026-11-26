<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function periods(): HasMany { return $this->hasMany(FiscalPeriod::class)->orderBy('number'); }

    public function isClosed(): bool { return $this->status === 'closed'; }
}
