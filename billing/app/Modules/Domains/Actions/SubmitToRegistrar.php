<?php

namespace App\Modules\Domains\Actions;

use App\Contracts\Domains\Registrar\ManagesLock;
use App\Contracts\Domains\Registrar\ReconcilesSubmissions;
use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\TransfersDomains;
use App\Data\Domains\Registrar\DomainInfo;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Domains\Registrar\SubmissionLookup;
use App\Data\Services\ProvisionResult;
use App\Enums\ReconcileStatus;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainTransferInitiated;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\DomainDates;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\DomainStateMachine;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Domains\Support\Nameservers;
use App\Modules\Domains\Support\RemoteIds;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;
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
 *  - A TLD the client registers at the registry (`.np`, registrars.md §9), or a `.np` name its registry cannot
 *    issue, is never sent, whoever created the domain: it stays `pending` with the reason in `last_error`
 *    (Needs attention), RegistrarActionFailed and a staff task, as for an explicit registrar error.
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
                'not_submittable' => $this->failed($domain, $domain->type, (string) self::notSubmittable($domain)),
                'in_flight' => new ProvisionResult(false, 'A submission for this domain is already in progress.'),
                'unverified' => $this->reconcile($domain, $staffId),
                default => new ProvisionResult(false, "The domain is {$domain->status->label()}, not pending; nothing was submitted."),
            };
        }

        $capability = $domain->type === 'transfer' ? 'transfer' : 'register';
        $request = new RegistrationRequest(
            domain: $domain->toRef(),
            years: $domain->type === 'transfer' ? 1 : $domain->period_years,
            nameservers: Nameservers::normalise($domain->nameservers ?? []),
            contacts: $this->contacts->contactSet($domain),
            idProtection: $domain->id_protection,
            eppCode: $domain->epp_code,
        );

        try {
            $result = $capability === 'transfer'
                ? $this->gateway->forDomain($domain, 'transfer', TransfersDomains::class, fn (TransfersDomains $registrar, RegistrarConfig $config): RegistrarResult => $registrar->transfer($config, $request), $request->toArray(), $staffId, $request->secrets())
                : $this->gateway->forDomain($domain, 'register', RegistrarDriver::class, fn (RegistrarDriver $registrar, RegistrarConfig $config): RegistrarResult => $registrar->register($config, $request), $request->toArray(), $staffId, $request->secrets());
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
     * Why this domain is never sent to a registrar, or null when it may be. Matches the DomainRegistry and cart
     * rules: its TLD is registered by the client at the registry, or it is a `.np` name outside the registry's
     * second levels (`asha.np`), which no registrar can register.
     */
    private static function notSubmittable(Domain $domain): ?string
    {
        if ($domain->tldModel->registered_by_client) {
            return __(':tld domains are registered by the client at the registry, so :domain was not sent to a registrar. Cancel it, or set its status once the client has registered it.', ['tld' => $domain->tldModel->extension, 'domain' => $domain->name]);
        }

        if (Tld::registryLevelHint($domain->name) !== null) {
            return __(':domain cannot be registered: the .np registry issues names only under .com.np, .org.np and its other second levels. It was not sent to a registrar; cancel it.', ['domain' => $domain->name]);
        }

        return null;
    }

    /**
     * Lock the row and claim it for submission. An open claim is resolved first (something may have been sent);
     * only then is a new submission refused for a domain that is never sent to a registrar.
     *
     * @return 'claimed'|'manual'|'not_submittable'|'in_flight'|'unverified'|'not_pending'
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
            } elseif (self::notSubmittable($locked) !== null) {
                $result = 'not_submittable';
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

        if ($domain->type === 'register') {
            try {
                $info = $this->gateway->forDomain($domain, 'info', RegistrarDriver::class, fn (RegistrarDriver $registrar, RegistrarConfig $config): DomainInfo => $registrar->info($config, $domain->toRef()), staffId: $staffId);

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
                ReconcilesSubmissions::class,
                fn (ReconcilesSubmissions $registrar, RegistrarConfig $config): SubmissionLookup => $registrar->findSubmission($config, $domain->toRef(), $command, $submittedAt),
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
            $this->gateway->forDomain($domain, 'lock', ManagesLock::class, fn (ManagesLock $registrar, RegistrarConfig $config): RegistrarResult => $registrar->setLock($config, $domain->toRef(), true), ['locked' => true], $staffId);
            $domain->forceFill(['registrar_lock' => true])->save();
        } catch (RegistrarException) {
            // Locking is best effort; the registration itself succeeded.
        }
    }
}
