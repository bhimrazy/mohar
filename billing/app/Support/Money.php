<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable monetary amount stored in minor units (1/100 of the currency unit).
 *
 * All money in the database is an integer column of minor units plus a currency code.
 */
final readonly class Money implements JsonSerializable
{
    public function __construct(public int $amount, public string $currency)
    {
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("Invalid currency code [{$currency}].");
        }
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    /**
     * Build from a decimal string such as "12.34".
     */
    public static function fromDecimal(string|int|float $value, string $currency): self
    {
        return self::of((int) round(((float) $value) * 100, 0, PHP_ROUND_HALF_UP), $currency);
    }

    public function add(self ...$others): self
    {
        $total = $this->amount;

        foreach ($others as $other) {
            $this->assertSameCurrency($other);
            $total += $other->amount;
        }

        return new self($total, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int|float $factor): self
    {
        return new self((int) round($this->amount * $factor, 0, PHP_ROUND_HALF_UP), $this->currency);
    }

    /**
     * Percentage of this amount, e.g. percent(20) of 1000 = 200.
     */
    public function percent(int|float $rate): self
    {
        return $this->multiply($rate / 100);
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->amount <= $other->amount ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    /**
     * Split into parts proportional to the given ratios without losing minor units.
     *
     * @param  list<int|float>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        $sum = array_sum($ratios);

        if ($sum <= 0) {
            throw new InvalidArgumentException('Ratios must sum to a positive number.');
        }

        $remainder = $this->amount;
        $results = [];

        foreach ($ratios as $ratio) {
            $share = intdiv($this->amount * (int) round($ratio * 1000), (int) round($sum * 1000));
            $results[] = $share;
            $remainder -= $share;
        }

        for ($i = 0; $remainder !== 0; $i = ($i + 1) % count($results)) {
            $step = $remainder > 0 ? 1 : -1;
            $results[$i] += $step;
            $remainder -= $step;
        }

        return array_values(array_map(fn (int $amount): self => new self($amount, $this->currency), $results));
    }

    /**
     * Decimal string, e.g. "12.34".
     */
    public function toDecimal(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $absolute = abs($this->amount);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public function format(): string
    {
        return $this->toDecimal().' '.$this->currency;
    }

    /**
     * @return array{amount: int, currency: string, decimal: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'decimal' => $this->toDecimal()];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Currency mismatch [{$this->currency}] vs [{$other->currency}].");
        }
    }
}
