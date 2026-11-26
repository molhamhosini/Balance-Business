<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetCategory extends Model
{
    protected $guarded = ['id'];

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Company::class); }

    public function assetAccount(): BelongsTo { return $this->belongsTo(Account::class, 'asset_account_id'); }
    public function accumulatedAccount(): BelongsTo { return $this->belongsTo(Account::class, 'accumulated_account_id'); }
    public function expenseAccount(): BelongsTo { return $this->belongsTo(Account::class, 'expense_account_id'); }

    public function getNameAttribute(): string { return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar; }
}
