<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    // =========================================================================
    // arsToUsd
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function converts_ars_to_usd_with_two_decimals(): void
    {
        $this->assertSame('100.00', Money::arsToUsd(120000, 1200));
        $this->assertSame('50.00', Money::arsToUsd('59000', '1180'));
        $this->assertSame('40.00', Money::arsToUsd(59000, 1475));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rounds_half_up_on_exact_midpoint(): void
    {
        // 201 / 200 = 1.005 → half-up → 1.01
        // (en float, round(1.005, 2) da 1.00 porque 1.005 no es representable)
        $this->assertSame('1.01', Money::arsToUsd(201, 200));

        // 57 / 200 = 0.285 → 0.29
        $this->assertSame('0.29', Money::arsToUsd(57, 200));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function accepts_float_inputs_without_scientific_notation_issues(): void
    {
        $this->assertSame('100.00', Money::arsToUsd(120000.0, 1200.0));
        $this->assertSame('50.42', Money::arsToUsd(59500.5, 1180.0));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function truncates_beyond_the_midpoint_correctly(): void
    {
        // 10000 / 3 = 3333.333... → 3333.33
        $this->assertSame('3333.33', Money::arsToUsd(10000, 3));
        // 20000 / 3 = 6666.666... → 6666.67
        $this->assertSame('6666.67', Money::arsToUsd(20000, 3));
    }

    // =========================================================================
    // sub / greaterThan / isZero
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function sub_returns_exact_difference(): void
    {
        $this->assertSame('10.00', Money::sub('50.00', '40.00'));
        $this->assertSame('-9.00', Money::sub('50.00', '59.00'));
        // Caso clásico de deriva float: 0.3 - 0.1 !== 0.2 en IEEE 754
        $this->assertSame('0.20', Money::sub('0.30', '0.10'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function greater_than_compares_at_two_decimals(): void
    {
        $this->assertTrue(Money::greaterThan('100.01', '100.00'));
        $this->assertFalse(Money::greaterThan('100.00', '100.00'));
        $this->assertFalse(Money::greaterThan('99.99', '100.00'));
        // Retirar exactamente todo el saldo debe estar permitido (no es "mayor")
        $this->assertFalse(Money::greaterThan(Money::arsToUsd(118000, 1180), '100.00'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function is_zero_detects_zero_without_epsilon(): void
    {
        $this->assertTrue(Money::isZero('0.00'));
        $this->assertTrue(Money::isZero(Money::sub('50.00', '50.00')));
        $this->assertFalse(Money::isZero('0.01'));
        $this->assertFalse(Money::isZero('-0.01'));
    }
}
