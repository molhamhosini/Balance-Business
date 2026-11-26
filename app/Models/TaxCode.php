<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCode extends Model
{
    public const TYPES = [
        'standard' => 'النسبة الأساسية', 'zero' => 'نسبة الصفر', 'exempt' => 'معفاة',
        'out_of_scope' => 'خارج النطاق', 'reverse_charge' => 'الاحتساب العكسي',
    ];

    protected $guarded = ['id'];

    protected $casts = ['rate' => 'decimal:2', 'is_active' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function outputAccount(): BelongsTo { return $this->belongsTo(Account::class, 'output_account_id'); }
    public function inputAccount(): BelongsTo { return $this->belongsTo(Account::class, 'input_account_id'); }

    public function getLabelAttribute(): string
    {
        return $this->code.' — '.$this->name_ar.' ('.rtrim(rtrim($this->rate, '0'), '.').'%)';
    }
}
