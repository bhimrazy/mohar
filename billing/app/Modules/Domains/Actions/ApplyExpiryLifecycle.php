<?php

namespace App\Modules\Domains\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainStateMachine;
use App\Modules\Domains\Services\RenewalInvoices;
use Carbon\CarbonImmutable;

/**
 * Date-based expiry transitions (spec §3, §4.5): active → grace → redemption → expired, with the grace/
 * redemption invoice swap (cancel + replacement with fee) and cancellation of the renewal invoice at
 * expiry. The redemption window follows the grace window. Only ever moves forward, so re-runs are no-ops.
 */
class ApplyExpiryLifecycle
{
    public function __construct(
        private DomainStateMachine $states,
        private RenewalInvoices $invoices,
        private Settings $settings,
        private ActivityLogger $activity,
    ) {}

    /**
     * @return int number of domains that changed status
     */
    public function handle(CarbonImmutable $today): int
    {
        $enabled = (bool) $this->settings->get('domains.grace_redemption_enabled', true);
        $changed = 0;

        Domain::query()
            ->with('tldModel')
            ->withStatus([DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today)
            ->orderBy('id')
            ->chunkById(200, function ($domains) use ($today, $enabled, &$changed): void {
                foreach ($domains as $domain) {
                    $target = $this->targetStatus($domain, $today, $enabled);

                    if ($target === null || $target === $domain->status || ! $domain->status->canTransitionTo($target)) {
                        continue;
                    }

                    $this->states->transition($domain, $target, "Expired on {$domain->expiry_date?->toDateString()}");
                    $this->swapInvoices($domain, $target);
                    $changed++;
                }
            });

        return $changed;
    }

    private function targetStatus(Domain $domain, CarbonImmutable $today, bool $enabled): ?DomainStatus
    {
        if ($domain->expiry_date === null) {
            return null;
        }

        $graceDays = $enabled ? $domain->tldModel->grace_days : 0;
        $redemptionDays = $enabled ? $domain->tldModel->redemption_days : 0;
        $daysPast = (int) $domain->expiry_date->diffInDays($today);

        return match (true) {
            $daysPast <= $graceDays => DomainStatus::Grace,
            $daysPast <= $graceDays + $redemptionDays => DomainStatus::Redemption,
            default => DomainStatus::Expired,
        };
    }

    private function swapInvoices(Domain $domain, DomainStatus $target): void
    {
        if (! $this->invoices->available()) {
            return;
        }

        if ($target === DomainStatus::Expired) {
            if ($this->invoices->cancelOpen($domain) > 0) {
                $this->activity->log("Renewal invoice of {$domain->name} cancelled at expiry", $domain, $domain->client_id);
            }

            return;
        }

        $kind = $target === DomainStatus::Grace ? 'grace' : 'redemption';
        $fee = $target === DomainStatus::Grace ? $domain->tldModel->grace_fee : $domain->tldModel->redemption_fee;

        if ($fee <= 0 || $this->invoices->open($domain)->isEmpty()) {
            return;
        }

        $this->invoices->cancelOpen($domain);
        $result = $this->invoices->issue($domain, $kind, $fee);
        $this->activity->log("Renewal invoice of {$domain->name} replaced with {$kind} fee invoice", $domain, $domain->client_id, ['invoice_id' => $result->invoiceId]);
    }
}
