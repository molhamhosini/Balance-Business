<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    public const EMIRATES = [
        'AUH' => 'أبو ظبي', 'DXB' => 'دبي', 'SHJ' => 'الشارقة', 'AJM' => 'عجمان',
        'UAQ' => 'أم القيوين', 'RAK' => 'رأس الخيمة', 'FUJ' => 'الفجيرة',
    ];

    /** VAT 201 box for standard-rated supplies per emirate. */
    public const VAT_BOX = ['AUH' => '1.1', 'DXB' => '1.2', 'SHJ' => '1.3', 'AJM' => '1.4', 'UAQ' => '1.5', 'RAK' => '1.6', 'FUJ' => '1.7'];

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class); }

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name_ar;
    }
}
