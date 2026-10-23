<?php

namespace App\Modules\Domains\Actions;

use App\Contracts\Domains\Registrar\ReconcilesSubmissions;
use App\Contracts\Domains\Registrar\SyncsDomains;
use App\Contracts\Domains\Registrar\TransfersDomains;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\SubmissionLookup;
use App\Data\Domains\Registrar\SyncResult;
use App\Data\Domains\Registrar\TransferSyncResult;
use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Enums\ReconcileStatus;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainTransferFailed;
use App\Events\Domains\DomainTransferred;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainDates;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\DomainStateMachine;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Domains\Services\RenewalInvoices;
use App\Modules\Domains\Support\DomainRegistrarLock;
use App\Modules\Domains\Support\RemoteIds;
use App\Support\Domains\CapabilityNotSupported;
use App\Support\Domains\RegistrarException;
use Carbon\CarbonImmutable;

/**
 * Reconcile one domain with its registrar (spec §9): transfer sync for pending transfers, status and
 * expiry sync for active / pending registrations. Returns the differences found; in notify-only mode
 * nothing is changed. It runs under the domain's registrar lock (the one the register, transfer and renew jobs hold);
 * when that is taken it does nothing and answers `busy` with a message. Expiry never moves backwards by more than a year without staff review, and
 * next_due_date is only moved when no renewal invoice is open.
 */
class SyncDomain
{
    public function __construct(
        private RegistrarGateway $gateway,
        private DomainStateMachine $states,
        private DomainDates $dates,
        private RenewalInvoices $invoices,
        private ActivityLogger $activity,
    ) {}

    /**
     * @return array{changes: list<array{domain_id: int, domain: string, field: string, from: string|null, to: string|null, applied: bool}>, error: string|null, busy?: true}
     */
    public function handle(Domain $domain, bool $notifyOnly = false, ?int $staffId = null): array
    {
        $lock = DomainRegistrarLock::for($domain->id);

        if (! $lock->get()) {
            return ['changes' => [], 'error' => __('Another registrar action is running for this domain. Try again in a minute.'), 'busy' => true];
        }

        try {
            return $this->locked($domain, $notifyOnly, $staffId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{changes: list<array{domain_id: int, domain: string, field: string, from: string|null, to: string|null, applied: bool}>, error: string|null}
     */
    private function locked(Domain $domain, bool $notifyOnly, ?int $staffId): array
    {
        $domain->refresh();

        try {
            $changes = $domain->status === DomainStatus::PendingTransfer
                ? $this->transferSync($domain, $notifyOnly, $staffId)
                : $this->sync($domain, $notifyOnly, $staffId);
        } catch (CapabilityNotSupported) {
            $domain->forceFill(['last_synced_at' => now(), 'last_sync_status' => 'unsupported'])->save();

            return ['changes' => [], 'error' => null];
        } catch (RegistrarException $exception) {
            $domain->forceFill(['last_synced_at' => now(), 'last_sync_status' => 'error', 'last_error' => $exception->getMessage()])->save();
            if (! $exception->retryable) {
                // A transient error (timeout, rate limit) says nothing about the registration; the next sync retries.
                $this->flagUnconfirmedRegistration($domain, $exception->getMessage());
            }

            return ['changes' => [], 'error' => $exception->getMessage()];
        }

        $domain->forceFill(['last_synced_at' => now(), 'last_sync_status' => 'ok'])->save();

        return ['changes' => $changes, 'error' => null];
    }

    /**
     * A registration whose answer was lost keeps its claim. The order id may be recorded from the registrar's own
     * submission lookup, inside that claim's window, and from nowhere else: sync never links an order it merely finds.
     */
    private function linkFromSubmission(Domain $domain, ?int $staffId): void
    {
        $claimed = $domain->registrar_meta['submission'] ?? null;

        if ($domain->status !== DomainStatus::PendingRegistration || $claimed === null || isset($domain->registrar_meta['remote']['order_id'])
            || ! $this->gateway->supports($domain, 'reconcile')) {
            return;
        }

        $at = CarbonImmutable::parse((string) $claimed);

        try {
            $lookup = $this->gateway->forDomain(
                $domain,
                'reconcile',
                ReconcilesSubmissions::class,
                fn (ReconcilesSubmissions $registrar, RegistrarConfig $config): SubmissionLookup => $registrar->findSubmission($config, $domain->toRef(), 'register', $at),
                ['command' => 'register', 'submitted_at' => $at->toIso8601String()],
                $staffId,
            );
        } catch (RegistrarException) {
            return;
        }

        if (in_array($lookup->status, [ReconcileStatus::Executed, ReconcileStatus::InProgress], true)) {
            $this->rememberRemote($domain, $lookup->remote, false);
            $domain->refresh();
        }
    }

    /**
     * A registration that is still not confirmed a quarter of an hour after it was submitted, and that sync cannot
     * settle (the registrar's answer gave no order to follow), is listed in Needs attention instead of waiting silently.
     */
    private function flagUnconfirmedRegistration(Domain $domain, string $reason): void
    {
        $submitted = $domain->registrar_meta['submitted_at'] ?? null;

        if ($domain->status !== DomainStatus::PendingRegistration || $domain->needs_review || $submitted === null
            || CarbonImmutable::parse((string) $submitted)->gt(now()->subMinutes(SubmitToRegistrar::IN_FLIGHT_MINUTES))) {
            return;
        }

        $domain->forceFill(['needs_review' => true, 'last_error' => $reason])->save();
        $this->activity->log("Staff task: registration of {$domain->name} not confirmed at the registrar", $domain, $domain->client_id);
    }

    /**
     * @return list<array{domain_id: int, domain: string, field: string, from: string|null, to: string|null, applied: bool}>
     */
    private function transferSync(Domain $domain, bool $notifyOnly, ?int $staffId): array
    {
        $result = $this->gateway->forDomain($domain, 'transfer_sync', TransfersDomains::class, fn (TransfersDomains $registrar, RegistrarConfig $config): TransferSyncResult => $registrar->transferSync($config, $domain->toRef()), staffId: $staffId);
        $this->rememberRemote($domain, $result->remote, $notifyOnly);

        if ($result->completed) {
            $change = $this->change($domain, 'status', $domain->status->value, DomainStatus::Active->value, ! $notifyOnly);

            if (! $notifyOnly) {
                $this->dates->applyExpiry($domain, $result->expiryDate ?? $this->dates->extend($domain, 1));
                $this->states->transition($domain, DomainStatus::Active, 'Transfer completed', [
                    ...$this->resolved($domain),
                    'registration_date' => $domain->registration_date ?? $this->dates->companyDate(),
                    'expiry_date' => $domain->expiry_date,
                    'next_due_date' => $domain->next_due_date,
                    'next_invoice_date' => $domain->next_invoice_date,
                    'billing_anchor_day' => $domain->billing_anchor_day,
                    'epp_code' => null,
                    'last_error' => null,
                ]);
                DomainEvents::dispatch(new DomainTransferred($domain->id, $domain->client_id));
            }

            return [$change];
        }

        if ($result->failed) {
            $reason = $result->reason ?? 'The transfer was rejected.';
            $change = $this->change($domain, 'status', $domain->status->value, DomainStatus::Pending->value, ! $notifyOnly);

            if (! $notifyOnly) {
                $this->states->transition($domain, DomainStatus::Pending, 'Transfer failed: '.$reason, [...$this->resolved($domain), 'last_error' => $reason]);
                DomainEvents::dispatch(new DomainTransferFailed($domain->id, $domain->client_id, $reason));
            }

            return [$change];
        }

        return [];
    }

    /**
     * @return list<array{domain_id: int, domain: string, field: string, from: string|null, to: string|null, applied: bool}>
     */
    private function sync(Domain $domain, bool $notifyOnly, ?int $staffId): array
    {
        if (! $notifyOnly) {
            $this->linkFromSubmission($domain, $staffId);
        }

        $result = $this->gateway->forDomain($domain, 'sync', SyncsDomains::class, fn (SyncsDomains $registrar, RegistrarConfig $config): SyncResult => $registrar->sync($config, $domain->toRef()), staffId: $staffId);
        $apply = ! $notifyOnly;
        $this->rememberRemote($domain, $result->remote, $notifyOnly);

        if ($result->transferredAway || $result->cancelled) {
            $to = $result->transferredAway ? DomainStatus::TransferredAway : DomainStatus::Cancelled;

            if (! $domain->status->canTransitionTo($to)) {
                return [];
            }

            $change = $this->change($domain, 'status', $domain->status->value, $to->value, $apply);

            if ($apply) {
                $this->states->transition($domain, $to, 'Reported by the registrar sync');
                $this->invoices->cancelOpen($domain);
            }

            return [$change];
        }

        $changes = [];

        if ($result->active && $domain->status === DomainStatus::PendingRegistration) {
            $changes[] = $this->change($domain, 'status', $domain->status->value, DomainStatus::Active->value, $apply);

            if ($apply) {
                $this->dates->applyExpiry($domain, $result->expiryDate ?? $this->dates->extend($domain, $domain->period_years));
                $this->states->transition($domain, DomainStatus::Active, 'Registration confirmed by sync', [
                    ...$this->resolved($domain),
                    'registration_date' => $domain->registration_date ?? $this->dates->companyDate(),
                    'expiry_date' => $domain->expiry_date,
                    'next_due_date' => $domain->next_due_date,
                    'next_invoice_date' => $domain->next_invoice_date,
                    'billing_anchor_day' => $domain->billing_anchor_day,
                    'last_error' => null,
                ]);
                DomainEvents::dispatch(new DomainRegistered($domain->id, $domain->client_id));
            }

            return $changes;
        }

        $remote = $result->expiryDate;
        $local = $domain->expiry_date;

        if ($remote === null) {
            return $changes;
        }

        if ($local !== null && $remote->isSameDay($local)) {
            if ($apply) {
                $this->settleRenewalClaim($domain, $remote);
            }

            return $changes;
        }

        $backwards = $local !== null && $remote->lt($local->subYearNoOverflow());

        if ($backwards) {
            if ($apply) {
                $domain->forceFill(['last_error' => "Registrar expiry {$remote->toDateString()} is more than a year before ours; review before applying."])->save();
                $this->activity->log("Staff review: registrar expiry of {$domain->name} moved backwards", $domain, $domain->client_id, ['local' => $local->toDateString(), 'remote' => $remote->toDateString()]);
            }

            return [...$changes, $this->change($domain, 'expiry_date', $local->toDateString(), $remote->toDateString(), false)];
        }

        $changes[] = $this->change($domain, 'expiry_date', $local?->toDateString(), $remote->toDateString(), $apply);

        if ($apply) {
            $openInvoice = $this->invoices->available()
                && $this->invoices->invoicer()->hasOpenInvoiceFor(BillableType::Domain, $domain->id);
            $this->dates->applyExpiry($domain, $remote, updateDueDates: ! $openInvoice);
            $domain->save();
            $this->settleRenewalClaim($domain, $remote);
        }

        return $changes;
    }

    /**
     * A renewal whose answer was lost keeps its claim and the review flag. When the registrar's expiry has reached
     * the end of the claimed cycle, the renewal is confirmed: close the claim so the next cycle renews on its own.
     */
    private function settleRenewalClaim(Domain $domain, CarbonImmutable $remote): void
    {
        $meta = $domain->registrar_meta ?? [];
        $cycle = $meta['renew_sent_for'] ?? null;

        if ($cycle === null || $cycle === 'none') {
            return;
        }

        $target = BillingCycle::Annually->advance(CarbonImmutable::parse((string) $cycle), (int) ($meta['renew_years'] ?? 1), $domain->billing_anchor_day);

        if ($remote->lt($target)) {
            return;
        }

        unset($meta['renew_sent_for'], $meta['renew_sent_at'], $meta['renew_years']);
        $domain->forceFill(['registrar_meta' => $meta ?: null, 'needs_review' => false, 'last_error' => null])->save();
        $this->activity->log("Renewal of {$domain->name} confirmed by sync", $domain, $domain->client_id, ['expiry' => $remote->toDateString()]);
    }

    /**
     * @param  array<string, string|int>  $remote
     */
    private function rememberRemote(Domain $domain, array $remote, bool $notifyOnly): void
    {
        if ($remote !== [] && ! $notifyOnly) {
            $domain->forceFill(['registrar_meta' => RemoteIds::merge($domain->registrar_meta, $remote)])->save();
        }
    }

    /**
     * Attributes that close an open submission once the registrar reported its outcome.
     *
     * @return array{registrar_meta: array<string, mixed>|null, needs_review: false}
     */
    private function resolved(Domain $domain): array
    {
        $meta = $domain->registrar_meta ?? [];
        unset($meta['submission'], $meta['submitted_at']);

        return ['registrar_meta' => $meta ?: null, 'needs_review' => false];
    }

    /**
     * @return array{domain_id: int, domain: string, field: string, from: string|null, to: string|null, applied: bool}
     */
    private function change(Domain $domain, string $field, ?string $from, ?string $to, bool $applied): array
    {
        return ['domain_id' => $domain->id, 'domain' => $domain->name, 'field' => $field, 'from' => $from, 'to' => $to, 'applied' => $applied];
    }
}
