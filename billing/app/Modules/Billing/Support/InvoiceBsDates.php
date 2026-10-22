<?php

namespace App\Modules\Billing\Support;

use DateTimeInterface;
use OutOfRangeException;

/**
 * The Bikram Sambat date printed beside an invoice's AD issue and due dates when the "billing.show_bs_dates" setting is
 * on (issue #207): invoice pages, the PDF and invoice emails all read it here.
 */
final class InvoiceBsDates
{
    public function __construct(private BillingSettings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->bool('show_bs_dates');
    }

    /**
     * "28 Poush 2082 BS", or null when the setting is off, there is no date, or the date is outside the BS table
     * (1943 to 2044 AD), where the AD date alone is shown.
     */
    public function label(?DateTimeInterface $date): ?string
    {
        if ($date === null || ! $this->enabled()) {
            return null;
        }

        try {
            return BikramSambat::fromAd($date)->format();
        } catch (OutOfRangeException) {
            return null;
        }
    }
}
