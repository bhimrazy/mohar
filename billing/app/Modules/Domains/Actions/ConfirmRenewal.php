<?php

namespace App\Modules\Domains\Actions;

use App\Events\Domains\DomainRenewed;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainDates;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\DomainStateMachine;
use App\Modules\Domains\Support\RemoteIds;
use Carbon\CarbonImmutable;

/**
 * The one step that records a renewal as done, whether the renew call, a registrar lookup or a sync proved it:
 * new expiry and billing dates, status back to active (also from grace, redemption or expired), the renewal claim
 * and review flag closed, and DomainRenewed fired once.
 */
class ConfirmRenewal
{
    public function __construct(
        private DomainStateMachine $states,
        private DomainDates $dates,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  array<string, string|int>  $remote
     */
    public function handle(Domain $domain, int $years, CarbonImmutable $expiry, array $remote = []): void
    {
        $this->dates->applyExpiry($domain, $expiry);
        $meta = RemoteIds::merge($domain->registrar_meta, $remote) ?? [];
        unset($meta['renew_sent_for'], $meta['renew_sent_at'], $meta['renew_years']);

        $this->states->transition($domain, DomainStatus::Active, "Renewed for {$years} year(s)", [
            'registrar_meta' => $meta ?: null,
            'expiry_date' => $domain->expiry_date,
            'next_due_date' => $domain->next_due_date,
            'next_invoice_date' => $domain->next_invoice_date,
            'billing_anchor_day' => $domain->billing_anchor_day,
            'last_error' => null,
            'needs_review' => false,
        ], force: $domain->status === DomainStatus::Expired);

        $this->activity->log("Domain renewed for {$years} year(s) until {$expiry->toDateString()}", $domain, $domain->client_id);
        DomainEvents::dispatch(new DomainRenewed($domain->id, $domain->client_id, $years));
    }
}
