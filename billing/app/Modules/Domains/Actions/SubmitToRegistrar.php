<?php

namespace App\Modules\Domains\Actions;

use App\Data\Services\ProvisionResult;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainTransferInitiated;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrationRequest;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Exceptions\RegistrarTimeout;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainDates;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\DomainStateMachine;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Domains\Support\Nameservers;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Submit a pending registration or transfer-in to the registrar (spec §4.2, §4.6).
 *
 *  - Only `pending` domains are submitted; anything else is a no-op (replays, duplicate jobs).
 *  - A previous submission is reconciled through `info` before re-submitting (no double registration).
 *  - Timeout / unknown result → `pending_registration`, never re-registered automatically.
 *  - Explicit registrar error → stays `pending`, RegistrarActionFailed, staff task.
 *  - Retryable (transient) errors are re-thrown so the queued job retries.
 */
class SubmitToRegistrar
{
    public function __construct(
        private RegistrarGateway $gateway,
        private DomainStateMachine $states,
        private DomainDates $dates,
        private BuildDomainContacts $contacts,
        private ActivityLogger $activity,
        private Settings $settings,
    ) {}

    /**
     * @throws RegistrarException when the error is retryable
     */
    public function handle(Domain $domain, ?int $staffId = null): ProvisionResult
    {
        $domain->refresh();

        if ($domain->status !== DomainStatus::Pending) {
            return new ProvisionResult(false, "The domain is {$domain->status->label()}, not pending; nothing was submitted.");
        }

        $domain->registrar_key ??= $domain->tldModel->registrar_key;

        if ($domain->registrar_key === null) {
            $this->activity->log("Staff task: {$domain->type} {$domain->name} manually at the registrar", $domain, $domain->client_id);
            $domain->save();

            return new ProvisionResult(false, 'This TLD is handled manually; complete it at the registrar and set the status.');
        }

        $capability = $domain->type === 'transfer' ? 'transfer' : 'register';

        if ($this->alreadyExistsAtRegistrar($domain, $staffId)) {
            return $this->succeeded($domain, new RegistrarResult('completed', $domain->expiry_date, 'Found at the registrar; not re-submitted.'));
        }

        $request = new RegistrationRequest(
            domain: $domain,
            years: $domain->type === 'transfer' ? 1 : $domain->period_years,
            nameservers: Nameservers::normalise($domain->nameservers ?? []),
            contacts: $this->contacts->contactSet($domain),
            idProtection: $domain->id_protection,
            eppCode: $domain->epp_code,
        );

        $domain->registrar_meta = [...($domain->registrar_meta ?? []), 'submitted_at' => now()->toIso8601String()];
        $domain->save();

        try {
            $result = $this->gateway->forDomain(
                $domain,
                $capability,
                fn (Registrar $registrar): RegistrarResult => $capability === 'transfer' ? $registrar->transfer($request) : $registrar->register($request),
                $request->toArray(),
                $staffId,
            );
        } catch (RegistrarTimeout $timeout) {
            return $this->unknownOutcome($domain, $timeout);
        } catch (RegistrarException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            return $this->failed($domain, $capability, $exception->getMessage());
        }

        return $this->succeeded($domain, $result, $staffId);
    }

    /**
     * Mark a failed attempt after the queue gave up on retryable errors.
     */
    public function failed(Domain $domain, string $capability, string $error): ProvisionResult
    {
        $domain->forceFill(['last_error' => $error])->save();
        $this->activity->log("Staff task: {$capability} of {$domain->name} failed", $domain, $domain->client_id, ['error' => $error]);
        DomainEvents::dispatch(new RegistrarActionFailed($domain->id, $domain->client_id, $capability, $error));

        return new ProvisionResult(false, $error);
    }

    private function succeeded(Domain $domain, RegistrarResult $result, ?int $staffId = null): ProvisionResult
    {
        $today = CarbonImmutable::today();

        if ($domain->type === 'transfer') {
            $this->states->transition($domain, DomainStatus::PendingTransfer, 'Transfer requested at the registrar', [
                'last_error' => null,
                'registrar_key' => $domain->registrar_key,
            ]);
            DomainEvents::dispatch(new DomainTransferInitiated($domain->id, $domain->client_id));

            return new ProvisionResult(true, $result->message ?? 'Transfer requested.');
        }

        if (! $result->isCompleted()) {
            $this->states->transition($domain, DomainStatus::PendingRegistration, 'Registration accepted asynchronously', ['last_error' => null]);

            return new ProvisionResult(true, $result->message ?? 'Registration submitted; awaiting confirmation.');
        }

        $this->dates->applyExpiry($domain, $result->expiryDate ?? $today->addYearsNoOverflow($domain->period_years));
        $this->states->transition($domain, DomainStatus::Active, 'Registered at the registrar', [
            'registration_date' => $domain->registration_date ?? $today,
            'expiry_date' => $domain->expiry_date,
            'next_due_date' => $domain->next_due_date,
            'next_invoice_date' => $domain->next_invoice_date,
            'billing_anchor_day' => $domain->billing_anchor_day,
            'registrar_key' => $domain->registrar_key,
            'last_error' => null,
        ]);
        DomainEvents::dispatch(new DomainRegistered($domain->id, $domain->client_id));
        $this->lockByDefault($domain, $staffId);

        return new ProvisionResult(true, $result->message ?? 'Domain registered.');
    }

    private function unknownOutcome(Domain $domain, RegistrarTimeout $timeout): ProvisionResult
    {
        $status = $domain->type === 'transfer' ? DomainStatus::PendingTransfer : DomainStatus::PendingRegistration;
        $this->states->transition($domain, $status, 'Registrar timed out; awaiting sync', ['last_error' => $timeout->getMessage()]);
        $this->activity->log("Registrar timed out for {$domain->name}; the next sync or \"check status\" resolves it", $domain, $domain->client_id);

        return new ProvisionResult(false, $timeout->getMessage());
    }

    /**
     * After an earlier submission, ask the registrar before submitting again.
     */
    private function alreadyExistsAtRegistrar(Domain $domain, ?int $staffId): bool
    {
        if ($domain->type !== 'register' || ! isset($domain->registrar_meta['submitted_at']) || ! $this->gateway->supports($domain, 'info')) {
            return false;
        }

        try {
            $info = $this->gateway->forDomain($domain, 'info', fn (Registrar $registrar): DomainInfo => $registrar->info($domain), staffId: $staffId);
        } catch (Throwable) {
            return false;
        }

        if ($info->status !== 'active') {
            return false;
        }

        $domain->expiry_date = $info->expiryDate;

        return true;
    }

    private function lockByDefault(Domain $domain, ?int $staffId): void
    {
        if (! $this->settings->get('domains.lock_by_default', true) || ! $this->gateway->supports($domain, 'lock')) {
            return;
        }

        try {
            $this->gateway->forDomain($domain, 'lock', fn (Registrar $registrar): RegistrarResult => $registrar->setLock($domain, true), ['locked' => true], $staffId);
            $domain->forceFill(['registrar_lock' => true])->save();
        } catch (RegistrarException) {
            // Locking is best effort; the registration itself succeeded.
        }
    }
}
