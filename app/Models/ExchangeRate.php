<?php

namespace App\Models;

use App\Exceptions\AccountingException;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    public const CURRENCIES = ['USD' => 'دولار أمريكي', 'EUR' => 'يورو', 'GBP' => 'جنيه إسترليني', 'SAR' => 'ريال سعودي', 'OMR' => 'ريال عماني', 'KWD' => 'دينار كويتي', 'BHD' => 'دينار بحريني', 'QAR' => 'ريال قطري', 'INR' => 'روبية هندية', 'PKR' => 'روبية باكستانية', 'AUD' => 'دولار أسترالي', 'NZD' => 'دولار نيوزيلندي', 'BRL' => 'ريال برازيلي'];

    protected $guarded = ['id'];

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Company::class); }

    protected $casts = ['date' => 'date', 'rate' => 'decimal:6'];

    /** AED per unit on a date: the latest rate on or before it. */
    public static function rateFor(int $companyId, string $currency, string $date): string
    {
        if ($currency === 'AED') {
            return '1';
        }
        $rate = static::where('company_id', $companyId)->where('currency', $currency)->whereDate('date', '<=', $date)->orderByDesc('date')->value('rate');
        if ($rate === null) {
            throw new AccountingException(__('No exchange rate for :c on or before :d.', ['c' => $currency, 'd' => $date]));
        }

        return (string) $rate;
    }
}
