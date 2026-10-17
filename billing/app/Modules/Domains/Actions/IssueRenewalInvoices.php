<?php

namespace App\Modules\Domains\Actions;

use App\Enums\BillableType;
use App\Events\Domains\FreeDomainRenewalBlocked;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\RenewalInvoices;
use Carbon\CarbonImmutable;

/**
 * Daily renewal invoicing (spec §4.3, automation stage RENEWALS). Idempotent per cycle: an invoice is
 * issued only when Billing has none for the period starting at next_due_date.
 */
class IssueRenewalInvoices
{
    public function __construct(
        private RenewalInvoices $invoices,
        private Settings $settings,
        private ActivityLogger $activity,
    ) {}

    /**
     * @return int number of invoices issued
     */
    public function handle(CarbonImmutable $today): int
    {
        $invoicer = $this->invoices->invoicer();
        $issued = 0;

        Domain::query()
            ->withStatus(DomainStatus::Active)
            ->where('auto_renew', true)
            ->whereDate('next_invoice_date', '<=', $today)
            ->chunkById(200, function ($domains) use ($invoicer, &$issued): void {
                foreach ($domains as $domain) {
                    if ($invoicer->hasInvoiceForPeriod(BillableType::Domain, $domain->id, $domain->next_due_date)) {
                        continue;
                    }

                    if ($domain->isFree() && ! $this->freeRenewalAllowed($domain)) {
                        continue;
                    }

                    $this->invoices->issue($domain);
                    $issued++;
                }
            });

        return $issued;
    }

    /**
     * Free (bundled) domains renew only alongside an active linked service on the same due date.
     */
    private function freeRenewalAllowed(Domain $domain): bool
    {
        if (! $this->settings->get('domains.free_renewal_requires_service', true)) {
            return true;
        }

        $reason = match (true) {
            $domain->linked_service_id === null => 'No linked hosting service.',
            $domain->linked_service_status !== 'active' => 'The linked service is '.($domain->linked_service_status ?? 'not active').'.',
            $domain->linked_service_due_date === null || ! $domain->linked_service_due_date->isSameDay($domain->next_due_date) => 'The linked service is due on a different date.',
            default => null,
        };

        if ($reason === null) {
            return true;
        }

        $alreadyBlocked = ($domain->registrar_meta['free_renewal_blocked_for'] ?? null) === $domain->next_due_date->toDateString();

        if (! $alreadyBlocked) {
            $domain->forceFill(['registrar_meta' => [...($domain->registrar_meta ?? []), 'free_renewal_blocked_for' => $domain->next_due_date->toDateString()]])->save();
            $this->activity->log("Staff task: free domain {$domain->name} was not renewed", $domain, $domain->client_id, ['reason' => $reason]);
            DomainEvents::dispatch(new FreeDomainRenewalBlocked($domain->id, $domain->client_id, $reason));
        }

        return false;
    }
}
