<?php

namespace App\Modules\Domains\Actions;

use App\Events\Domains\DomainSyncReport;
use App\Events\Domains\DomainTransferStale;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\TransferDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainEvents;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Registrar sync run (spec §9): every pending transfer (at most every transfer_sync_interval_hours),
 * then a batch of active / pending-registration domains, oldest last_synced_at first, each at most
 * every sync_interval_hours. Emits one DomainSyncReport when anything changed or failed.
 *
 * Before that it sweeps stale submission claims (issue #12, registrars.md C3): a worker killed mid-submission
 * leaves a paid domain `pending` with its claim, and no job ever comes back for it. Each one is flagged for
 * review with a staff task, listed in the report, and handed back to its registrar job, which verifies the claim
 * with the registrar and never re-sends.
 */
class SyncDomains
{
    public function __construct(
        private SyncDomain $sync,
        private ActivityLogger $activity,
        private Settings $settings,
    ) {}

    /**
     * @param  int|null  $budgetSeconds  stop taking new domains after this long (the rest go first next run)
     * @return array{synced: int, changes: int, errors: int}
     */
    public function handle(?CarbonImmutable $now = null, ?int $budgetSeconds = null): array
    {
        $started = hrtime(true);
        $now ??= CarbonImmutable::now();
        $notifyOnly = (bool) $this->settings->get('domains.sync_notify_only', false);
        // The sweep runs even with sync off: it only verifies interrupted submissions, and without it they stay unseen.
        $errors = $this->sweepStaleClaims($now);

        if (! $this->settings->get('domains.sync_enabled', true)) {
            if ($errors !== []) {
                DomainEvents::dispatch(new DomainSyncReport([], $errors, $notifyOnly));
            }

            return ['synced' => 0, 'changes' => 0, 'errors' => count($errors)];
        }

        $transferHours = (int) $this->settings->get('domains.transfer_sync_interval_hours', 1);
        $syncHours = (int) $this->settings->get('domains.sync_interval_hours', 4);
        $batch = max(1, (int) $this->settings->get('domains.sync_batch_size', 50));

        $transfers = $this->due(DomainStatus::PendingTransfer, $now->subHours($transferHours))->get();
        $others = $this->due([DomainStatus::Active, DomainStatus::PendingRegistration], $now->subHours($syncHours))->limit($batch)->get();

        $changes = [];
        $synced = 0;

        foreach ($transfers->concat($others) as $domain) {
            if ($budgetSeconds !== null && (hrtime(true) - $started) / 1e9 >= $budgetSeconds) {
                break;
            }

            $result = $this->sync->handle($domain, $notifyOnly);

            if (($result['busy'] ?? false)) {
                continue; // a registrar job has it; last_synced_at is untouched, so it is first in line next run
            }

            $synced++;
            array_push($changes, ...$result['changes']);

            if ($result['error'] !== null) {
                $errors[] = ['domain_id' => $domain->id, 'domain' => $domain->name, 'error' => $result['error']];
            }
        }

        $this->flagStaleTransfers($now);

        if ($changes !== [] || $errors !== []) {
            DomainEvents::dispatch(new DomainSyncReport($changes, $errors, $notifyOnly));
        }

        return ['synced' => $synced, 'changes' => count($changes), 'errors' => count($errors)];
    }

    /**
     * @param  list<DomainStatus>|DomainStatus  $statuses
     * @return Builder<Domain>
     */
    private function due(array|DomainStatus $statuses, CarbonImmutable $syncedBefore): Builder
    {
        return Domain::query()
            ->withStatus($statuses)
            ->whereNotNull('registrar_key')
            ->where(fn (Builder $query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', $syncedBefore))
            ->orderByRaw('case when last_synced_at is null then 0 else 1 end')
            ->orderBy('last_synced_at')
            ->orderBy('id');
    }

    /**
     * @return list<array{domain_id: int, domain: string, error: string}>
     */
    private function sweepStaleClaims(CarbonImmutable $now): array
    {
        $cutoff = $now->subMinutes(SubmitToRegistrar::IN_FLIGHT_MINUTES);
        $swept = [];

        $stale = Domain::query()
            ->withStatus(DomainStatus::Pending)
            ->where('needs_review', false) // swept once already: listed in Needs attention, not logged again each hour
            ->whereNotNull('registrar_meta')
            ->orderBy('id')
            ->get()
            ->filter(fn (Domain $domain): bool => SubmitToRegistrar::hasOpenClaim($domain)
                && CarbonImmutable::parse((string) $domain->registrar_meta['submission'])->lte($cutoff));

        foreach ($stale as $domain) {
            $domain->forceFill(['needs_review' => true])->save();
            $this->activity->log("Staff task: the {$domain->type} of {$domain->name} stopped before the registrar answered", $domain, $domain->client_id);
            dispatch($domain->type === 'transfer' ? new TransferDomain($domain->id) : new RegisterDomain($domain->id));
            $swept[] = ['domain_id' => $domain->id, 'domain' => $domain->name, 'error' => 'The submission was interrupted; it is being verified with the registrar.'];
        }

        return $swept;
    }

    private function flagStaleTransfers(CarbonImmutable $now): void
    {
        $days = (int) $this->settings->get('domains.transfer_stale_days', 14);

        Domain::query()
            ->withStatus(DomainStatus::PendingTransfer)
            ->whereNull('transfer_stale_notified_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Domain $domain): bool => isset($domain->registrar_meta['submitted_at'])
                && CarbonImmutable::parse((string) $domain->registrar_meta['submitted_at'])->lte($now->subDays($days)))
            ->each(function (Domain $domain) use ($days, $now): void {
                $domain->forceFill(['transfer_stale_notified_at' => $now])->save();
                DomainEvents::dispatch(new DomainTransferStale($domain->id, $domain->client_id, $days));
            });
    }
}
