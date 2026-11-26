<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parsing_and_rounding(): void
    {
        $this->assertSame(123457, Money::toCents('1,234.567'));
        $this->assertSame(-1050, Money::toCents('-10.5'));
        $this->assertSame('1234.57', Money::fromCents(123457));
        $this->assertSame('-0.05', Money::fromCents(-5));
        $this->assertSame('1,234.57', Money::format('1234.57'));
    }

    public function test_vat_rounds_half_up_on_the_line(): void
    {
        $this->assertSame(5000, Money::percent(100000, '5'));
        $this->assertSame(3, Money::percent(50, '5')); // 2.5 fils -> 3
    }
}
