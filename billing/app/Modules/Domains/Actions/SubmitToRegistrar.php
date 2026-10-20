<?php

namespace App\Modules\Domains\Actions;

use App\Data\Services\ProvisionResult;
use App\Enums\ReconcileStatus;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainTransferInitiated;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrationRequest;
use App\Modules\Domains\Drivers\Registrars\Data\SubmissionLookup;
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
use App\Modules\Domains\Support\RemoteIds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Submit a pending registration or transfer-in to the registrar (spec §4.2, §4.6).
 *
 *  - The domain is claimed under a row lock (registrar_meta.submission) before anything is sent, so the
 *    payment job, a staff command and Orders' process() can never submit the same domain twice.
 *  - A claim that is still present on entry means an earlier attempt's outcome is unknown (crash, lost
 *    response): it is verified with the driver's `findSubmission` (capability `reconcile`), else through `info`
 *    (registrations), and never re-sent. SyncDomains sweeps claims that no job will come back for (issue #12).
 *  - Timeout / unknown result → `pending_registration` / `pending_transfer` flagged needs_review.
 *  - Explicit registrar error → stays `pending`, RegistrarActionFailed, staff task; the claim is released.
 *  - Retryable errors (known not executed) release the claim and are re-thrown for the queue to retry.
 */
class SubmitToRegistrar
{
    /** A claim younger than this is treated as an attempt still running, not as a lost one. */
    public const IN_FLIGHT_MINUTES = 15;

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
        $claim = $this->claim($domain);

        if ($claim !== 'claimed') {
            return match ($claim) {
                'manual' => $this->manual($domain),
                'in_flight' => new ProvisionResult(false, 'A submission for this domain is already in progress.'),
                'unverified' => $this->reconcile($domain, $staffId),
                default => new ProvisionResult(false, "The domain is {$domain->status->label()}, not pending; nothing was submitted."),
            };
        }

        $capability = $domain->type === 'transfer' ? 'transfer' : 'register';
        $request = new RegistrationRequest(
            domain: $domain,
            years: $domain->type === 'transfer' ? 1 : $domain->period_years,
            nameservers: Nameservers::normalise($domain->nameservers ?? []),
            contacts: $this->contacts->contactSet($domain),
            idProtection: $domain->id_protection,
            eppCode: $domain->epp_code,
        );

        try {
            $result = $this->gateway->forDomain(
                $domain,
                $capability,
                fn (Registrar $registrar): RegistrarResult => $capability === 'transfer' ? $registrar->transfer($request) : $registrar->register($request),
                $request->toArray(),
                $staffId,
            );
        } catch (RegistrarTimeout $timeout) {
            return $this->unknownOutcome($domain, $timeout->getMessage());
        } catch (RegistrarException $exception) {
            $this->releaseClaim($domain);

            if ($exception->retryable) {
                throw $exception;
            }

            return $this->failed($domain, $capability, $exception->getMessage());
        }

        return $this->succeeded($domain, $result, $staffId);
    }

    /**
     * Mark a failed attempt (also called when the queue gave up on retryable errors).
     */
    public function failed(Domain $domain, string $capability, string $error): ProvisionResult
    {
        $domain->forceFill(['last_error' => $error])->save();
        $this->activity->log("Staff task: {$capability} of {$domain->name} failed", $domain, $domain->client_id, ['error' => $error]);
        DomainEvents::dispatch(new RegistrarActionFailed($domain->id, $domain->client_id, $capability, $error));

        return new ProvisionResult(false, $error);
    }

    /**
     * Whether a submission is claimed and not yet resolved (staff must check status instead of re-submitting).
     */
    public static function hasOpenClaim(Domain $domain): bool
    {
        return isset($domain->registrar_meta['submission']);
    }

    /**
     * Lock the row and claim it for submission.
     *
     * @return 'claimed'|'manual'|'in_flight'|'unverified'|'not_pending'
     */
    private function claim(Domain $domain): string
    {
        return DB::transaction(function () use ($domain): string {
            $locked = Domain::query()->with('tldModel')->lockForUpdate()->findOrFail($domain->id);
            $result = 'claimed';

            if ($locked->status !== DomainStatus::Pending) {
                $result = 'not_pending';
            } elseif (self::hasOpenClaim($locked)) {
                $claimedAt = CarbonImmutable::parse((string) $locked->registrar_meta['submission']);
                $result = $claimedAt->gt(now()->subMinutes(self::IN_FLIGHT_MINUTES)) ? 'in_flight' : 'unverified';
            } else {
                $locked->registrar_key ??= $locked->tldModel->registrar_key;

                if ($locked->registrar_key === null) {
                    $result = 'manual';
                } else {
                    $now = now()->toIso8601String();
                    $locked->registrar_meta = [...($locked->registrar_meta ?? []), 'submission' => $now, 'submitted_at' => $now];
                    $locked->save();
                }
            }

            $domain->setRawAttributes($locked->getAttributes(), true);
            $domain->setRelation('tldModel', $locked->tldModel);

            return $result;
        });
    }

    private function releaseClaim(Domain $domain): void
    {
        $meta = $domain->registrar_meta ?? [];
        unset($meta['submission']);
        $domain->forceFill(['registrar_meta' => $meta ?: null])->save();
    }

    private function manual(Domain $domain): ProvisionResult
    {
        $this->activity->log("Staff task: {$domain->type} {$domain->name} manually at the registrar", $domain, $domain->client_id);

        return new ProvisionResult(false, 'This TLD is handled manually; complete it at the registrar and set the status.');
    }

    /**
     * An earlier attempt left its claim: verify with the registrar instead of submitting again (registrars.md C2).
     */
    private function reconcile(Domain $domain, ?int $staffId): ProvisionResult
    {
        if ($this->gateway->supports($domain, 'reconcile')) {
            $lookup = $this->lookup($domain, $staffId);

            return match ($lookup->status) {
                ReconcileStatus::Executed => $this->succeeded($domain, new RegistrarResult('completed', $lookup->expiryDate, 'Found at the registrar; not re-submitted.', remote: $lookup->remote), $staffId),
                ReconcileStatus::InProgress => $this->succeeded($domain, new RegistrarResult('pending', message: 'The registrar is still processing the earlier submission; not re-submitted.', remote: $lookup->remote), $staffId),
                ReconcileStatus::NotExecuted => $this->unknownOutcome($domain, 'The registrar has no record of the earlier submission. To submit it again, set the status to Pending, then register it.', $lookup->remote),
                ReconcileStatus::Conflict => $this->unknownOutcome($domain, $lookup->message ?? 'The name is registered, but not in our registrar account. Decide with the client before doing anything else.', $lookup->remote),
                ReconcileStatus::Unknown => $this->unknownOutcome($domain, $lookup->message ?? 'The registrar could not confirm the earlier submission; verify it there before retrying.', $lookup->remote),
            };
        }

        if ($domain->type === 'register' && $this->gateway->supports($domain, 'info')) {
            try {
                $info = $this->gateway->forDomain($domain, 'info', fn (Registrar $registrar): DomainInfo => $registrar->info($domain), staffId: $staffId);

                if ($info->status === 'active') {
                    return $this->succeeded($domain, new RegistrarResult('completed', $info->expiryDate, 'Found at the registrar; not re-submitted.', remote: $info->remote), $staffId);
                }
            } catch (Throwable) {
                // Unverifiable right now: fall through to staff review.
            }
        }

        return $this->unknownOutcome($domain, 'An earlier submission did not finish; verify at the registrar before retrying.');
    }

    private function lookup(Domain $domain, ?int $staffId): SubmissionLookup
    {
        $command = $domain->type === 'transfer' ? 'transfer' : 'register';
        $submittedAt = CarbonImmutable::parse((string) ($domain->registrar_meta['submission'] ?? now()->toIso8601String()));

        try {
            return $this->gateway->forDomain(
                $domain,
                'reconcile',
                fn (Registrar $registrar): SubmissionLookup => $registrar->findSubmission($domain, $command, $submittedAt),
                ['command' => $command, 'submitted_at' => $submittedAt->toIso8601String()],
                $staffId,
            );
        } catch (Throwable) {
            return new SubmissionLookup(ReconcileStatus::Unknown);
        }
    }

    private function succeeded(Domain $domain, RegistrarResult $result, ?int $staffId = null): ProvisionResult
    {
        $today = CarbonImmutable::today();
        $meta = RemoteIds::merge($domain->registrar_meta, $result->remote) ?? [];
        unset($meta['submission']);
        $resolved = ['last_error' => null, 'needs_review' => false, 'registrar_meta' => $meta ?: null, 'registrar_key' => $domain->registrar_key];

        if ($domain->type === 'transfer') {
            $this->states->transition($domain, DomainStatus::PendingTransfer, 'Transfer requested at the registrar', $resolved);
            DomainEvents::dispatch(new DomainTransferInitiated($domain->id, $domain->client_id));

            return new ProvisionResult(true, $result->message ?? 'Transfer requested.');
        }

        if (! $result->isCompleted()) {
            $this->states->transition($domain, DomainStatus::PendingRegistration, 'Registration accepted asynchronously', $resolved);

            return new ProvisionResult(true, $result->message ?? 'Registration submitted; awaiting confirmation.');
        }

        $this->dates->applyExpiry($domain, $result->expiryDate ?? $today->addYearsNoOverflow($domain->period_years));
        $this->states->transition($domain, DomainStatus::Active, 'Registered at the registrar', [
            ...$resolved,
            'registration_date' => $domain->registration_date ?? $today,
            'expiry_date' => $domain->expiry_date,
            'next_due_date' => $domain->next_due_date,
            'next_invoice_date' => $domain->next_invoice_date,
            'billing_anchor_day' => $domain->billing_anchor_day,
        ]);
        DomainEvents::dispatch(new DomainRegistered($domain->id, $domain->client_id));
        $this->lockByDefault($domain, $staffId);

        return new ProvisionResult(true, $result->message ?? 'Domain registered.');
    }

    /**
     * The registrar may or may not have executed the command: park the domain for sync / staff verification.
     * The claim stays in place so nothing re-submits automatically.
     *
     * @param  array<string, string|int>  $remote  ids a lookup found on the way
     */
    private function unknownOutcome(Domain $domain, string $message, array $remote = []): ProvisionResult
    {
        $status = $domain->type === 'transfer' ? DomainStatus::PendingTransfer : DomainStatus::PendingRegistration;
        $this->states->transition($domain, $status, 'Registrar outcome unknown; awaiting verification', [
            'last_error' => $message,
            'needs_review' => true,
            'registrar_meta' => RemoteIds::merge($domain->registrar_meta, $remote),
        ]);
        $this->activity->log("Staff task: verify the {$domain->type} of {$domain->name}; the next sync or \"Sync now\" resolves it", $domain, $domain->client_id);

        return new ProvisionResult(false, $message);
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
