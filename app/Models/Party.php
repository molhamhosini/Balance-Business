<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Customer or supplier. Per the client's chart ("دفتر مساعد لكل عميل/مورد"), each party is a
 * sub-ledger on the control account (1131 customers, 2111 suppliers): journal lines carry party_id.
 */
class Party extends Model
{
    public const TYPES = ['customer' => 'عميل', 'supplier' => 'مورد'];

    protected $guarded = ['id'];

    protected $casts = ['credit_limit' => 'decimal:2', 'is_related_party' => 'boolean', 'is_active' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function taxCode(): BelongsTo { return $this->belongsTo(TaxCode::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    public function getLabelAttribute(): string
    {
        return $this->code.' — '.$this->name;
    }

    /** Sub-ledger balance in fils, debit positive (customers owe us > 0; we owe suppliers < 0). */
    public function balance(?string $asOf = null): int
    {
        $r = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.party_id', $this->id)->whereIn('e.status', ['posted', 'reversed'])
            ->when($asOf, fn ($q) => $q->where('e.date', '<=', $asOf))
            ->selectRaw('COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c')->first();

        return Money::toCents((string) $r->d) - Money::toCents((string) $r->c);
    }

    /** Sequential code under the control account: 1131-0001, 1131-0002 … (suppliers 2111-0001 …). */
    public static function nextCode(int $companyId, string $type, string $controlCode): string
    {
        $prefix = $controlCode.'-';
        $last = static::where('company_id', $companyId)->where('type', $type)->where('code', 'like', $prefix.'%')
            ->lockForUpdate()->pluck('code')->map(fn ($c) => (int) substr($c, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
