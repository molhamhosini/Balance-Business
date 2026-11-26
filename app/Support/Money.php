<?php

namespace App\Support;

/**
 * Exact money arithmetic in integer fils (1/100 AED). Avoids floats entirely
 * and does not need the bcmath extension. Rounding: half away from zero, 2 dp.
 */
final class Money
{
    public static function toCents(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        $s = is_float($value) ? sprintf('%.6F', $value) : trim(str_replace([',', ' '], '', (string) $value));
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '+-');
        if (! preg_match('/^\d*(\.\d*)?$/', $s)) {
            throw new \InvalidArgumentException("Invalid amount: {$value}");
        }
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $frac = str_pad($frac, 3, '0');
        $cents = ((int) ($int === '' ? '0' : $int)) * 100 + (int) substr($frac, 0, 2);
        if ((int) $frac[2] >= 5) {
            $cents++;
        }

        return $neg ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Percentage of an amount, rounded half away from zero. $rate like "5.00". */
    public static function percent(int $cents, string|float $rate): int
    {
        $bp = self::toCents($rate); // 5.00% => 500 basis points
        $raw = $cents * $bp; // in 1/10000
        $q = intdiv(abs($raw) + 5000, 10000);

        return $raw < 0 ? -$q : $q;
    }

    public static function format(string|int|float|null $value): string
    {
        $c = is_int($value) ? $value : self::toCents($value);
        $sign = $c < 0 ? '-' : '';

        return $sign.number_format(intdiv(abs($c), 100)).'.'.str_pad((string) (abs($c) % 100), 2, '0', STR_PAD_LEFT);
    }
}
