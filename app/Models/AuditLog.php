<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Append-only (requirements §6.7). Also enforced by MySQL triggers. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'إنشاء', 'updated' => 'تعديل', 'deleted' => 'حذف مسودة', 'posted' => 'ترحيل',
        'reversed' => 'عكس', 'cancelled' => 'إلغاء', 'period_closed' => 'إقفال فترة',
        'period_reopened' => 'إعادة فتح فترة', 'year_closed' => 'إقفال سنة', 'imported' => 'استيراد',
    ];

    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new AccountingException('The audit log is append-only.'));
        static::deleting(fn () => throw new AccountingException('The audit log is append-only.'));
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function auditable(): MorphTo { return $this->morphTo(); }

    public static function record(string $action, ?Model $model, ?array $before = null, ?array $after = null, ?string $reason = null, ?int $companyId = null): self
    {
        return self::create([
            'company_id' => $companyId ?? $model?->company_id,
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $model ? $model->getMorphClass() : null,
            'auditable_id' => $model?->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => request()?->ip(),
        ]);
    }
}
