<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    public const CLASSES = [
        'asset' => 'أصول', 'liability' => 'خصوم', 'equity' => 'حقوق ملكية',
        'revenue' => 'إيرادات', 'cogs' => 'تكلفة المبيعات', 'expense' => 'مصاريف',
    ];

    /** Fixed first level (requirements §2). */
    public const ROOT_CLASS = ['1' => 'asset', '2' => 'liability', '3' => 'equity', '4' => 'revenue', '5' => 'cogs', '6' => 'expense'];

    public const SYSTEM_KEYS = [
        'vat_output' => 'ضريبة المخرجات',
        'vat_input' => 'ضريبة المدخلات',
        'retained_earnings' => 'الأرباح المبقاة',
        'current_year_pl' => 'ربح/خسارة السنة الحالية',
        'fx_gain' => 'أرباح فروق العملة',
        'fx_loss' => 'خسائر فروق العملة',
        'inventory_variance' => 'فروقات الجرد',
        'customers' => 'الحساب الرئيسي للعملاء',
        'suppliers' => 'الحساب الرئيسي للموردين',
        'banks' => 'الحساب الرئيسي للبنوك',
        'cheques_in' => 'شيكات برسم التحصيل',
        'cheques_out' => 'شيكات مؤجلة الدفع',
        'inventory' => 'المخزون (افتراضي للأصناف)',
        'cogs' => 'تكلفة المبيعات (افتراضي للأصناف)',
        'freight_in' => 'تكاليف الشحن والتخليص',
        'purchase_returns' => 'مردودات المشتريات (الجرد الدوري)',
        'depreciation' => 'مصروف الإهلاك',
        'asset_gain' => 'أرباح بيع أصول',
        'asset_loss' => 'خسائر بيع أصول',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'is_postable' => 'boolean', 'is_active' => 'boolean', 'is_cash' => 'boolean',
        'is_bank' => 'boolean', 'is_non_deductible' => 'boolean', 'is_related_party' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Account $a) {
            if ($a->parent_id) {
                $parent = Account::findOrFail($a->parent_id);
                if ($parent->lines()->exists()) {
                    throw new AccountingException(__('Cannot add a sub-account under an account that already has postings.'));
                }
                if ($parent->level >= 4) {
                    throw new AccountingException(__('The chart of accounts is limited to 4 levels.'));
                }
                $a->level = $parent->level + 1;
                $a->class ??= $parent->class;
                $a->statement ??= $parent->statement;
                $a->nature ??= $parent->nature;
                $a->statement_line ??= $parent->statement_line;
            } else {
                $a->level = 1;
            }
        });

        static::created(function (Account $a) {
            if ($a->parent_id) {
                Account::whereKey($a->parent_id)->update(['is_postable' => false]);
            }
        });

        static::deleting(function (Account $a) {
            if ($a->lines()->exists()) {
                throw new AccountingException(__('An account with postings cannot be deleted; deactivate it instead.'));
            }
            if ($a->children()->exists()) {
                throw new AccountingException(__('Delete or move the sub-accounts first.'));
            }
            if ($a->system_key) {
                throw new AccountingException(__('This account is bound to a system setting and cannot be deleted.'));
            }
        });

        static::deleted(function (Account $a) {
            if ($a->parent_id && ! Account::where('parent_id', $a->parent_id)->exists()) {
                Account::whereKey($a->parent_id)->update(['is_postable' => true]);
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function parent(): BelongsTo { return $this->belongsTo(Account::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(Account::class, 'parent_id')->orderBy('code'); }
    public function lines(): HasMany { return $this->hasMany(JournalLine::class); }

    public function scopePostable(Builder $q): Builder
    {
        return $q->where('is_postable', true)->where('is_active', true);
    }

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }

    public function getLabelAttribute(): string
    {
        return $this->code.' — '.$this->name;
    }

    /** IDs of this account and every descendant (for roll-ups and drill-down). */
    public function descendantIds(): array
    {
        $all = Account::where('company_id', $this->company_id)->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [$this->id];
        $stack = [$this->id];
        while ($stack) {
            $id = array_pop($stack);
            foreach ($all->get($id, collect()) as $child) {
                $ids[] = $child->id;
                $stack[] = $child->id;
            }
        }

        return $ids;
    }

    /** Suggest the next free code under this parent (e.g. 1110 -> 1117, 1100 -> 1180). */
    public static function suggestCode(Account $parent): string
    {
        $codes = Account::where('parent_id', $parent->id)->pluck('code')->map(fn ($c) => (int) $c);
        $step = match (true) {
            $parent->level === 1 => 100,
            $parent->level === 2 => 10,
            default => 1,
        };
        $base = (int) $parent->code;
        $next = $codes->isEmpty() ? $base + $step : $codes->max() + $step;

        return (string) $next;
    }
}
