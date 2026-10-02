<?php

namespace Tests\Unit;

use App\NepaliDate;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class ExactValuesTest extends TestCase
{
    public function test_exact_money_rounding_and_discount(): void
    {
        $this->assertSame(12345, Money::parse('१२३.४५'));
        $this->assertSame(1, Money::multiplyDivide(1, 1, 2));
        $this->assertSame(-1, Money::multiplyDivide(-1, 1, 2));
        $this->assertSame([1, 0, 0], Money::allocate(1, [100, 100, 100]));
        $this->assertSame('123.45', Money::format(12345));
    }

    public function test_parser_rejects_exponents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::parse('1e3');
    }

    public function test_calendar_boundaries(): void
    {
        $this->assertSame(20830115, NepaliDate::normalize('२०८३-०१-१५'));
        $this->assertSame('2083/2084', NepaliDate::fiscalYearLabel(20830401));
        $this->assertSame('2082/2083', NepaliDate::fiscalYearLabel(20830331));
        $this->assertSame(1, NepaliDate::daysBetween(20830332, 20830401));
        $this->assertSame(20830101, NepaliDate::fromAd('2026-04-14'));
    }
}
