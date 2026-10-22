<?php

namespace App\Modules\Billing\Support;

/**
 * A Bikram Sambat calendar date (month 1 = Baisakh). Made by BikramSambat::fromAd().
 */
final readonly class BikramSambatDate
{
    public function __construct(public int $year, public int $month, public int $day) {}

    public function monthName(): string
    {
        return BikramSambat::MONTHS[$this->month - 1];
    }

    /** "28 Poush 2082 BS". */
    public function format(): string
    {
        return sprintf('%d %s %d BS', $this->day, $this->monthName(), $this->year);
    }
}
