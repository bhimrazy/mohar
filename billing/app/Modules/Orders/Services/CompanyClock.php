<?php

namespace App\Modules\Orders\Services;

use App\Modules\Core\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * "Today" in the company timezone (setting system.timezone, falling back to app.timezone).
 * Promotion dates and invoice periods are calendar dates in this timezone.
 */
class CompanyClock
{
    public function __construct(private Settings $settings) {}

    public function timezone(): string
    {
        return (string) ($this->settings->get('system.timezone') ?: config('app.timezone', 'UTC'));
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /**
     * Today's calendar date at midnight (company timezone), e.g. for invoice periods.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->now()->toDateString());
    }
}
