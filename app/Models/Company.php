<?php

namespace App\Models;

use Filament\Models\Contracts\HasCurrentTenantLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model implements HasCurrentTenantLabel
{
    protected $guarded = ['id'];

    protected $casts = [
        'segregation_of_duties' => 'boolean',
        'allow_negative_cash' => 'boolean',
        'voucher_approval_limit' => 'decimal:2',
    ];

    public function getCurrentTenantLabel(): string
    {
        return __('Current company');
    }

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    public function users(): BelongsToMany { return $this->belongsToMany(User::class); }
    public function branches(): HasMany { return $this->hasMany(Branch::class); }
    public function accounts(): HasMany { return $this->hasMany(Account::class); }
    public function fiscalYears(): HasMany { return $this->hasMany(FiscalYear::class); }
    public function fiscalPeriods(): HasMany { return $this->hasMany(FiscalPeriod::class); }
    public function taxCodes(): HasMany { return $this->hasMany(TaxCode::class); }
    public function journalEntries(): HasMany { return $this->hasMany(JournalEntry::class); }
    public function vouchers(): HasMany { return $this->hasMany(Voucher::class); }
    public function parties(): HasMany { return $this->hasMany(Party::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function auditLogs(): HasMany { return $this->hasMany(AuditLog::class); }

    public function systemAccount(string $key): ?Account
    {
        return $this->accounts()->where('system_key', $key)->first();
    }
}
