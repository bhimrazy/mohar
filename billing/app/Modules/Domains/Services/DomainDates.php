<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Core\CompanyClock;
use App\Enums\BillingCycle;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Models\Domain;
use Carbon\CarbonImmutable;

/**
 * Derives the billing dates of a domain from its expiry (spec §4.3):
 * next_due_date = expiry − domains.next_due_offset_days, next_invoice_date = next_due − lead days.
 */
class DomainDates
{
    public function __construct(private Settings $settings, private CompanyClock $clock) {}

    /** The company date as a date value (ADR 0013). */
    public function companyDate(): CarbonImmutable
    {
        return $this->clock->todayDate();
    }

    public function leadDays(): int
    {
        // A blank saved before the field became required reads as the shipped default.
        return (int) ($this->settings->get('domains.invoice_lead_days') ?? config('domains.invoice_lead_days', 30));
    }

    public function dueOffsetDays(): int
    {
        return (int) $this->settings->get('domains.next_due_offset_days', 0);
    }

    /**
     * Set expiry and (optionally) the derived due/invoice dates on the model, without saving.
     */
    public function applyExpiry(Domain $domain, CarbonImmutable $expiry, bool $updateDueDates = true): void
    {
        $domain->expiry_date = $expiry;
        $domain->billing_anchor_day ??= $expiry->day;

        if ($updateDueDates) {
            $domain->next_due_date = $expiry->subDays($this->dueOffsetDays());
            $domain->next_invoice_date = $domain->next_due_date->subDays($this->leadDays());
        }
    }

    /**
     * Expiry after adding $years to the current expiry (or today), keeping the anchor day (Feb 29 stays anchored).
     */
    public function extend(Domain $domain, int $years, ?CarbonImmutable $today = null): CarbonImmutable
    {
        $base = $domain->expiry_date ?? ($today ?? $this->companyDate());

        return BillingCycle::Annually->advance($base, $years, $domain->billing_anchor_day ?? $base->day);
    }
}
