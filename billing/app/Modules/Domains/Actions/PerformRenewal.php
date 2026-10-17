<?php

namespace App\Modules\Domains\Actions;

use App\Data\Services\ProvisionResult;
use App\Events\Domains\DomainRenewed;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainDates;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\DomainStateMachine;
use App\Modules\Domains\Services\RegistrarGateway;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Renew a domain for N years (spec §4.4): on success expiry += N (or the registrar's date), the
 * billing dates are recomputed and the status returns to active. Manual domains are extended locally
 * with a staff task. A renewal already applied (retry after a lost response) is never repeated.
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
     * @param  string|null  $fromExpiry  expiry when the renewal was requested; a later expiry means it was already applied
     *
     * @throws RegistrarException when the error is retryable
     */
    public function handle(Domain $domain, int $years, ?string $fromExpiry = null, ?int $staffId = null): ProvisionResult
    {
        $domain->refresh();
        $renewable = [DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption];

        if ($staffId !== null) {
            $renewable[] = DomainStatus::Expired;
        }

        if (! in_array($domain->status, $renewable, true)) {
            return $this->failed($domain, "A {$domain->status->label()} domain cannot be renewed automatically; staff review is needed.");
        }

        if ($fromExpiry !== null && $domain->expiry_date !== null && $domain->expiry_date->gt(CarbonImmutable::parse($fromExpiry))) {
            return new ProvisionResult(true, 'The renewal was already applied.');
        }

        if ($domain->registrar_key === null) {
            $this->activity->log("Staff task: renew {$domain->name} for {$years} year(s) at the registrar", $domain, $domain->client_id);

            return $this->applied($domain, $years, $this->dates->extend($domain, $years), 'Extended locally (manual registrar).');
        }

        $previouslySent = $fromExpiry !== null && ($domain->registrar_meta['renew_sent_for'] ?? null) === $fromExpiry;

        if ($previouslySent && ($reconciled = $this->renewedAtRegistrar($domain, (string) $fromExpiry, $staffId)) !== null) {
            return $this->applied($domain, $years, $reconciled, 'Renewal found at the registrar; not repeated.');
        }

        if ($fromExpiry !== null) {
            $domain->forceFill(['registrar_meta' => [...($domain->registrar_meta ?? []), 'renew_sent_for' => $fromExpiry]])->save();
        }

        $restore = $domain->status === DomainStatus::Redemption && $this->gateway->supports($domain, 'restore');

        try {
            $result = $this->gateway->forDomain(
                $domain,
                'renew',
                fn (Registrar $registrar): RegistrarResult => $registrar->renew($domain, $years, $restore),
                ['years' => $years, 'restore' => $restore],
                $staffId,
            );
        } catch (RegistrarException $exception) {
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
        ], force: $domain->status === DomainStatus::Expired);

        $this->activity->log("Domain renewed for {$years} year(s) until {$expiry->toDateString()}", $domain, $domain->client_id);
        DomainEvents::dispatch(new DomainRenewed($domain->id, $domain->client_id, $years));

        return new ProvisionResult(true, $message);
    }

    private function renewedAtRegistrar(Domain $domain, string $fromExpiry, ?int $staffId): ?CarbonImmutable
    {
        if (! $this->gateway->supports($domain, 'info')) {
            return null;
        }

        try {
            $info = $this->gateway->forDomain($domain, 'info', fn (Registrar $registrar): DomainInfo => $registrar->info($domain), staffId: $staffId);
        } catch (Throwable) {
            return null;
        }

        return $info->expiryDate !== null && $info->expiryDate->gt(CarbonImmutable::parse($fromExpiry)) ? $info->expiryDate : null;
    }
}
