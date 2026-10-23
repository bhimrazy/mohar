<?php

namespace App\Modules\Domains\Actions;

use App\Data\Billing\InvoiceResult;
use App\Enums\BillableType;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\DomainActionNotAllowed;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainPricingService;
use App\Modules\Domains\Services\RenewalInvoices;
use Illuminate\Validation\ValidationException;

/**
 * "Renew now" (spec §8): issue the renewal invoice for the current cycle immediately instead of waiting
 * for the automated lead time. Clients pick a term at the current renew price; staff bill the locked
 * renewal amount and period. Never issues a second invoice for the same period.
 */
class IssueRenewalNow
{
    public function __construct(
        private RenewalInvoices $invoices,
        private DomainPricingService $pricing,
        private Settings $settings,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  'client'|'staff'  $actor
     *
     * @throws DomainActionNotAllowed
     */
    public function handle(Domain $domain, string $actor, ?int $years = null): InvoiceResult
    {
        if ($domain->isRegisteredByClient()) {
            throw ValidationException::withMessages(['domain' => __('A name registered at the registry by the client is renewed there, so no renewal invoice is created.')]);
        }

        if (! $this->invoices->available()) {
            throw new DomainActionNotAllowed('Renewal invoices cannot be created right now. Please try again later.');
        }

        if (! in_array($domain->status, [DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption], true)) {
            throw new DomainActionNotAllowed("A {$domain->status->label()} domain cannot be renewed here.");
        }

        if ($this->invoices->invoicer()->hasInvoiceForPeriod(BillableType::Domain, $domain->id, $domain->next_due_date)) {
            throw new DomainActionNotAllowed('A renewal invoice for this period already exists; pay it to renew the domain.');
        }

        [$years, $amount] = $actor === 'client' ? $this->clientTerm($domain, $years) : [$domain->period_years, $domain->recurring_amount];

        $kind = match ($domain->status) {
            DomainStatus::Grace => 'grace',
            DomainStatus::Redemption => 'redemption',
            default => 'renewal',
        };
        $fee = match ($kind) {
            'grace' => $domain->tldModel->grace_fee,
            'redemption' => $domain->tldModel->redemption_fee,
            default => 0,
        };

        $result = $this->invoices->issue($domain, $kind, $fee, $years, $amount);
        $this->activity->log("Renewal invoice {$result->number} created by {$actor} for {$years} year(s)", $domain, $domain->client_id, ['invoice_id' => $result->invoiceId]);

        return $result;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function clientTerm(Domain $domain, ?int $years): array
    {
        if (! $this->settings->get('domains.enable_renewal_orders', true)) {
            throw new DomainActionNotAllowed('Online renewals are switched off. Please contact us to renew.');
        }

        if ($domain->isFree() && $domain->linked_service_id !== null && $domain->linked_service_status === 'active') {
            throw new DomainActionNotAllowed('This domain is included with your hosting and renews with it.');
        }

        $terms = $this->pricing->renewalTerms($domain, $domain->currency, $this->pricing->groupOf($domain->client_id));
        $term = collect($terms)->firstWhere('years', $years ?? ($terms[0]['years'] ?? null));

        if ($term === null) {
            throw new DomainActionNotAllowed('This renewal term is not available for your domain.');
        }

        return [$term['years'], $term['price']];
    }
}
