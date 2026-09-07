<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('normalizationCases')]
    public function test_it_normalizes_and_rounds_plain_decimal_values(string|int $input, string $expected): void
    {
        $this->assertSame($expected, Money::of($input)->decimal());
    }

    /** @return array<string, array{string|int, string}> */
    public static function normalizationCases(): array
    {
        return [
            'integer' => [12, '12.00'],
            'one decimal place' => ['12.5', '12.50'],
            'round half up' => ['12.345', '12.35'],
            'negative half up' => ['-12.345', '-12.35'],
            'below half' => ['0.004', '0.00'],
            'negative zero' => ['-0.001', '0.00'],
        ];
    }

    public function test_totals_and_subtraction_remain_decimal_safe(): void
    {
        $total = Money::sum(['0.10', '0.20', Money::of('14.995')]);

        $this->assertSame('15.30', $total->decimal());
        $this->assertSame('15.05', $total->subtract('0.25')->decimal());
    }

    public function test_line_amounts_are_rounded_after_multiplication(): void
    {
        $this->assertSame('24.99', Money::of('19.99')->multiply('1.25')->decimal());
        $this->assertSame('0.01', Money::of('0.01')->multiply('0.5')->decimal());
    }

    public function test_workflow_threshold_comparisons_use_exact_decimal_values(): void
    {
        $threshold = Money::of('1000.00');

        $this->assertFalse(Money::of('999.999')->isGreaterThan($threshold));
        $this->assertTrue(Money::of('1000.01')->isGreaterThan($threshold));
        $this->assertTrue(Money::of('1000')->isGreaterThanOrEqual($threshold));
    }

    public function test_formatting_does_not_convert_through_floating_point(): void
    {
        $this->assertSame('MYR 1,234,567.89', Money::of('1234567.89')->format());
        $this->assertSame('USD -12.50', Money::of('-12.5')->format('USD'));
    }

    #[DataProvider('invalidAmountCases')]
    public function test_it_rejects_non_plain_decimal_input(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of($input);
    }

    /** @return array<string, array{string}> */
    public static function invalidAmountCases(): array
    {
        return [
            'scientific notation' => ['1e3'],
            'thousands separator' => ['1,000.00'],
            'leading whitespace' => [' 10.00'],
            'currency text' => ['MYR 10.00'],
        ];
    }

    public function test_it_rejects_values_beyond_database_precision(): void
    {
        $this->expectException(OverflowException::class);
        Money::of('10000000000000.00');
    }

    public function test_quantity_is_limited_to_four_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('10')->multiply('1.00001');
    }
}
