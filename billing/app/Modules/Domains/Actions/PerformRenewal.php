<?php

namespace App\Modules\Domains\Actions;

use App\Data\Services\ProvisionResult;
use App\Enums\BillingCycle;
use App\Events\Domains\DomainRenewed;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Exceptions\RegistrarTimeout;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainDates;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\DomainStateMachine;
use App\Modules\Domains\Services\RegistrarGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Renew a domain for N years (spec §4.4): on success expiry += N (or the registrar's date), the billing
 * dates are recomputed and the status returns to active. Manual domains are extended locally with a staff
 * task.
 *
 * Exactly-once: the renewal for a cycle is claimed under a row lock (registrar_meta.renew_sent_for) before
 * the registrar is called. A claim found on entry means an earlier attempt's outcome is unknown; it is
 * verified through `info` when the driver supports it, otherwise the domain is flagged for staff review —
 * a renewal is never re-sent automatically. A renewal is "already applied" only when the expiry reached
 * the requested cycle's end (fromExpiry + years), so an unrelated sync shift never swallows a paid renewal.
 */
class PerformRenewal
{
    public function __construct(
        private RegistrarGateway $gateway,
        private DomainStateMachine $states,
        private DomainDates $dates,
        private ActivityLogger $activity,
    ) {}

    /**
     * @param  string|null  $fromExpiry  expiry when the renewal was requested (identifies the cycle)
     *
     * @throws RegistrarException when the error is retryable
     */
    public function handle(Domain $domain, int $years, ?string $fromExpiry = null, ?int $staffId = null): ProvisionResult
    {
        $domain->refresh();
        $fromExpiry ??= $domain->expiry_date?->toDateString();
        $renewable = [DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption];

        if ($staffId !== null) {
            $renewable[] = DomainStatus::Expired;
        }

        if (! in_array($domain->status, $renewable, true)) {
            return $this->failed($domain, "A {$domain->status->label()} domain cannot be renewed automatically; staff review is needed.");
        }

        $target = $fromExpiry === null ? null : BillingCycle::Annually->advance(CarbonImmutable::parse($fromExpiry), $years, $domain->billing_anchor_day);

        if ($target !== null && $domain->expiry_date !== null && $domain->expiry_date->gte($target)) {
            return new ProvisionResult(true, 'The renewal was already applied.');
        }

        if ($domain->registrar_key === null) {
            $this->activity->log("Staff task: renew {$domain->name} for {$years} year(s) at the registrar", $domain, $domain->client_id);

            return $this->applied($domain, $years, $this->dates->extend($domain, $years), 'Extended locally (manual registrar).');
        }

        $cycle = $fromExpiry ?? 'none';

        if (! $this->claim($domain, $cycle)) {
            return $this->reconcile($domain, $years, $target, $staffId);
        }

        $restore = $domain->status === DomainStatus::Redemption && $this->gateway->supports($domain, 'restore');

        try {
            $result = $this->gateway->forDomain(
                $domain,
                $restore ? 'restore' : 'renew',
                fn (Registrar $registrar): RegistrarResult => $registrar->renew($domain, $years, $restore),
                ['years' => $years, 'restore' => $restore],
                $staffId,
            );
        } catch (RegistrarTimeout $timeout) {
            return $this->unverified($domain, $timeout->getMessage());
        } catch (RegistrarException $exception) {
            $this->releaseClaim($domain);

            if ($exception->retryable) {
                throw $exception;
            }

            return $this->failed($domain, $exception->getMessage());
        }

        return $this->applied($domain, $years, $result->expiryDate ?? $this->dates->extend($domain, $years), $result->message ?? 'Domain renewed.');
    }

    public function failed(Domain $domain, string $error): ProvisionResult
    {
        $domain->forceFill(['last_error' => $error])->save();
        $this->activity->log("Staff task: renewal of {$domain->name} failed", $domain, $domain->client_id, ['error' => $error]);
        DomainEvents::dispatch(new RegistrarActionFailed($domain->id, $domain->client_id, 'renew', $error));

        return new ProvisionResult(false, $error);
    }

    /**
     * Claim the renewal of this cycle; false when an earlier attempt already holds a claim.
     */
    private function claim(Domain $domain, string $cycle): bool
    {
        return DB::transaction(function () use ($domain, $cycle): bool {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);

            if (isset($locked->registrar_meta['renew_sent_for'])) {
                $domain->setRawAttributes($locked->getAttributes(), true);

                return false;
            }

            $locked->forceFill(['registrar_meta' => [...($locked->registrar_meta ?? []), 'renew_sent_for' => $cycle]])->save();
            $domain->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    private function releaseClaim(Domain $domain): void
    {
        $meta = $domain->registrar_meta ?? [];
        unset($meta['renew_sent_for']);
        $domain->forceFill(['registrar_meta' => $meta ?: null])->save();
    }

    /**
     * An earlier attempt holds the claim: apply the renewal only if the registrar confirms it.
     */
    private function reconcile(Domain $domain, int $years, ?CarbonImmutable $target, ?int $staffId): ProvisionResult
    {
        if ($target !== null && $this->gateway->supports($domain, 'info')) {
            try {
                $info = $this->gateway->forDomain($domain, 'info', fn (Registrar $registrar): DomainInfo => $registrar->info($domain), staffId: $staffId);

                if ($info->expiryDate !== null && $info->expiryDate->gte($target)) {
                    return $this->applied($domain, $years, $info->expiryDate, 'Renewal found at the registrar; not repeated.');
                }
            } catch (Throwable) {
                // Unverifiable right now: staff review below.
            }
        }

        return $this->unverified($domain, 'An earlier renewal attempt did not finish; verify at the registrar before renewing again.');
    }

    private function unverified(Domain $domain, string $message): ProvisionResult
    {
        $domain->forceFill(['last_error' => $message, 'needs_review' => true])->save();
        $this->activity->log("Staff task: verify the renewal of {$domain->name} at the registrar", $domain, $domain->client_id, ['error' => $message]);
        DomainEvents::dispatch(new RegistrarActionFailed($domain->id, $domain->client_id, 'renew', $message));

        return new ProvisionResult(false, $message);
    }

    private function applied(Domain $domain, int $years, CarbonImmutable $expiry, string $message): ProvisionResult
    {
        $this->dates->applyExpiry($domain, $expiry);
        $meta = $domain->registrar_meta ?? [];
        unset($meta['renew_sent_for']);

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

        return new ProvisionResult(true, $message);
    }
}
