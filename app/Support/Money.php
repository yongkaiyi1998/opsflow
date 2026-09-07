<?php

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

final class Money
{
    private const MAX_MINOR_UNITS = 999999999999999;

    private const QUANTITY_SCALE = 10000;

    private function __construct(private readonly int $minorUnits) {}

    public static function of(string|int $amount): self
    {
        return new self(self::parseAmount($amount));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** @param iterable<self|string|int> $amounts */
    public static function sum(iterable $amounts): self
    {
        $total = self::zero();

        foreach ($amounts as $amount) {
            $total = $total->add($amount);
        }

        return $total;
    }

    public function add(self|string|int $amount): self
    {
        $other = self::coerce($amount);

        if (($other->minorUnits > 0 && $this->minorUnits > self::MAX_MINOR_UNITS - $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits < -self::MAX_MINOR_UNITS - $other->minorUnits)) {
            throw new OverflowException('The monetary amount exceeds DECIMAL(15,2).');
        }

        return new self($this->minorUnits + $other->minorUnits);
    }

    public function subtract(self|string|int $amount): self
    {
        $other = self::coerce($amount);

        if (($other->minorUnits < 0 && $this->minorUnits > self::MAX_MINOR_UNITS + $other->minorUnits)
            || ($other->minorUnits > 0 && $this->minorUnits < -self::MAX_MINOR_UNITS + $other->minorUnits)) {
            throw new OverflowException('The monetary amount exceeds DECIMAL(15,2).');
        }

        return new self($this->minorUnits - $other->minorUnits);
    }

    public function multiply(string|int $quantity): self
    {
        $quantityUnits = self::parseQuantity($quantity);
        $absoluteMinorUnits = abs($this->minorUnits);
        $wholeQuantity = intdiv($quantityUnits, self::QUANTITY_SCALE);
        $fractionalQuantity = $quantityUnits % self::QUANTITY_SCALE;

        if ($absoluteMinorUnits !== 0 && $wholeQuantity > intdiv(self::MAX_MINOR_UNITS, $absoluteMinorUnits)) {
            throw new OverflowException('The monetary amount exceeds DECIMAL(15,2).');
        }

        $wholeResult = $absoluteMinorUnits * $wholeQuantity;
        $fractionalProduct = intdiv($absoluteMinorUnits, self::QUANTITY_SCALE) * $fractionalQuantity;
        $fractionalRemainder = ($absoluteMinorUnits % self::QUANTITY_SCALE) * $fractionalQuantity;
        $fractionalResult = $fractionalProduct + intdiv($fractionalRemainder + 5000, self::QUANTITY_SCALE);

        if ($fractionalResult > self::MAX_MINOR_UNITS - $wholeResult) {
            throw new OverflowException('The monetary amount exceeds DECIMAL(15,2).');
        }

        $result = $wholeResult + $fractionalResult;

        return new self($this->minorUnits < 0 ? -$result : $result);
    }

    public function compare(self|string|int $amount): int
    {
        return $this->minorUnits <=> self::coerce($amount)->minorUnits;
    }

    public function isGreaterThan(self|string|int $amount): bool
    {
        return $this->compare($amount) > 0;
    }

    public function isGreaterThanOrEqual(self|string|int $amount): bool
    {
        return $this->compare($amount) >= 0;
    }

    public function decimal(): string
    {
        $absoluteMinorUnits = abs($this->minorUnits);
        $whole = intdiv($absoluteMinorUnits, 100);
        $fraction = $absoluteMinorUnits % 100;

        return ($this->minorUnits < 0 ? '-' : '').$whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    public function format(string $currency = 'MYR'): string
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be a three-letter uppercase code.');
        }

        [$whole, $fraction] = explode('.', ltrim($this->decimal(), '-'));
        $groupedWhole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return $currency.' '.($this->minorUnits < 0 ? '-' : '').$groupedWhole.'.'.$fraction;
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    private static function coerce(self|string|int $amount): self
    {
        return $amount instanceof self ? $amount : self::of($amount);
    }

    private static function parseAmount(string|int $amount): int
    {
        $value = (string) $amount;

        if (preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Amount must be a plain decimal value.');
        }

        $whole = ltrim($matches['whole'], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches['fraction'] ?? '', 3, '0');

        if (strlen($whole) > 13) {
            throw new OverflowException('The monetary amount exceeds DECIMAL(15,2).');
        }

        $minorUnits = ((int) $whole * 100) + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $minorUnits++;
        }

        if ($minorUnits > self::MAX_MINOR_UNITS) {
            throw new OverflowException('The monetary amount exceeds DECIMAL(15,2).');
        }

        return ($matches['sign'] ?? '') === '-' && $minorUnits !== 0 ? -$minorUnits : $minorUnits;
    }

    private static function parseQuantity(string|int $quantity): int
    {
        $value = (string) $quantity;

        if (preg_match('/^(?<whole>\d+)(?:\.(?<fraction>\d{1,4}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Quantity must be a non-negative decimal with at most four decimal places.');
        }

        $whole = ltrim($matches['whole'], '0');
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($whole) > 11) {
            throw new OverflowException('Quantity exceeds DECIMAL(15,4).');
        }

        return ((int) $whole * self::QUANTITY_SCALE) + (int) str_pad($matches['fraction'] ?? '', 4, '0');
    }
}
