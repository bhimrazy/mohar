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

    /** "2082-09-28", the sortable form stored on fiscal documents. */
    public function toIso(): string
    {
        return sprintf('%d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    /** "28 Poush 2082 BS". */
    public function format(): string
    {
        return sprintf('%d %s %d BS', $this->day, $this->monthName(), $this->year);
    }
}
