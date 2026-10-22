<?php

namespace App\Modules\Migration\Support;

use App\Contracts\Auth\ClientLoginInvitations;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Jobs\SendInvitations;
use App\Modules\Migration\Models\MigrationInvitation;
use App\Modules\Migration\Models\MigrationRun;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Send invitations" for a completed client import (client-import spec §5.7): the owner logins of the run's clients
 * that the import created and nobody has used since (no sign-in, no two-factor authentication, the password it was
 * created with, which nobody knows). Closed clients are never invited. Sending queues them; SendInvitations sends at
 * most migration.invite_per_hour an hour through the ClientLoginInvitations contract.
 *
 * @phpstan-type Filters array{scope: 'created'|'all', group: string|null, status: 'active'|'inactive'|null}
 */
class ImportInvitations
{
    public function __construct(private ClientLoginInvitations $invitations, private ActivityLogger $audit) {}

    /**
     * Why nothing can be sent for this run now, or null. Availability is the contract's to say (ADR 0012 addendum).
     */
    public function blocked(MigrationRun $run): ?string
    {
        if ($run->status !== MigrationRun::COMPLETED) {
            return __('Invitations can be sent once the import has finished.');
        }

        return $this->invitations->problem();
    }

    /**
     * The run's invitable owner logins with their invitation state, one row per login: user_id, client_id, the state
     * (not_invited, open, expired, queued) and what the page shows.
     *
     * @param  Filters  $filters
     */
    public function candidates(MigrationRun $run, array $filters): Builder
    {
        return DB::table('migration_records as r')
            ->join('clients as c', 'c.id', '=', 'r.target_id')
            ->join('client_user as cu', fn (JoinClause $join) => $join->on('cu.client_id', '=', 'c.id')->where('cu.is_owner', true))
            ->join('users as u', 'u.id', '=', 'cu.user_id')
            ->leftJoin('client_invitations as ci', fn (JoinClause $join) => $join->on('ci.client_id', '=', 'c.id')->on('ci.user_id', '=', 'u.id'))
            ->where('r.migration_run_id', $run->id)
            ->whereIn('r.outcome', $filters['scope'] === 'created' ? ['created'] : ['created', 'updated'])
            ->where('c.status', '!=', 'closed')
            ->where('u.is_staff', false)->where('u.is_active', true)
            ->whereNull('u.last_login_at')->whereNull('u.two_factor_secret')->whereNull('u.two_factor_confirmed_at')
            // Created by an import (a login staff chose to link is older than the import), and never used: the same rule as
            // Platform's PlatformClientLoginInvitations::neverUsed(), checked again when the invitation is sent.
            ->whereColumn('u.created_at', '>=', 'c.imported_at')
            ->where(fn (Builder $query) => $query->whereNull('u.password_changed_at')->orWhereColumn('u.password_changed_at', '<=', 'u.created_at'))
            ->whereNull('ci.accepted_at')
            ->when($filters['group'] === 'none', fn (Builder $query) => $query->whereNull('c.client_group_id'))
            ->when($filters['group'] !== null && $filters['group'] !== 'none', fn (Builder $query) => $query->where('c.client_group_id', (int) $filters['group']))
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('c.status', $filters['status']))
            ->select(['u.id as user_id', 'c.id as client_id', 'u.name', 'u.email', 'c.first_name', 'c.last_name', 'c.company_name', 'ci.sent_at as invited_at', 'ci.expires_at'])
            // Waiting: queued, or being sent by a live sender (a stale `sending` row is failed by the next sender).
            ->selectRaw("case when exists (select 1 from migration_invitations mi where mi.user_id = u.id and (mi.status = 'queued' or (mi.status = 'sending' and mi.updated_at > ?))) then 'queued' when ci.id is null then 'not_invited' when ci.expires_at > ? then 'open' else 'expired' end as state", [now()->subSeconds(MigrationInvitation::STALE_SECONDS), now()])
            // The reason this run's latest attempt was not sent, shown beside a login that is not invited.
            ->selectSub(fn (Builder $query) => $query->from('migration_invitations as last')->select('last.reason')->whereColumn('last.user_id', 'u.id')
                ->where('last.migration_run_id', $run->id)->whereIn('last.status', [MigrationInvitation::FAILED, 'refused'])->orderByDesc('last.id')->limit(1), 'not_sent_reason');
    }

    /**
     * Logins by state, counted in SQL.
     *
     * @param  Filters  $filters
     * @return array{total: int, not_invited: int, open: int, expired: int, queued: int}
     */
    public function counts(MigrationRun $run, array $filters): array
    {
        $row = DB::query()->fromSub($this->candidates($run, $filters), 'x')
            ->selectRaw("count(*) as total, sum(case when state = 'not_invited' then 1 else 0 end) as not_invited, sum(case when state = 'open' then 1 else 0 end) as open, sum(case when state = 'expired' then 1 else 0 end) as expired, sum(case when state = 'queued' then 1 else 0 end) as queued")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0), 'not_invited' => (int) ($row->not_invited ?? 0), 'open' => (int) ($row->open ?? 0),
            'expired' => (int) ($row->expired ?? 0), 'queued' => (int) ($row->queued ?? 0),
        ];
    }

    /**
     * Queues the logins that were never invited or whose invitation expired (an open invitation is not replaced in
     * bulk), in one statement, and starts the sender. Returns how many were queued.
     *
     * @param  Filters  $filters
     *
     * @throws ValidationException
     */
    public function queue(MigrationRun $run, array $filters, User $actor): int
    {
        if (($reason = $this->blocked($run)) !== null) {
            throw ValidationException::withMessages(['invitations' => $reason]);
        }

        $queued = DB::transaction(function () use ($run, $filters, $actor): int {
            // One queueing per run at a time: two clicks never queue a login twice.
            MigrationRun::query()->whereKey($run->id)->lockForUpdate()->first();
            // One INSERT … SELECT: nothing is read into PHP. The bound values are cast, so both databases type them.
            // The time is the application's now() (UTC), never the database clock: PostgreSQL's current_timestamp is in
            // the server's zone, which would displace the duplicate check's created_at. SQLite stores the bound text as
            // is (its cast to timestamp would make it a number).
            $now = now()->format('Y-m-d H:i:s');
            $at = DB::getDriverName() === 'pgsql' ? 'cast(? as timestamp)' : '?';
            $eligible = DB::query()->fromSub($this->candidates($run, $filters), 'x')->whereIn('state', ['not_invited', 'expired'])
                ->selectRaw("cast(? as bigint), client_id, user_id, cast(? as varchar(16)), cast(? as bigint), {$at}, {$at}", [$run->id, MigrationInvitation::QUEUED, $actor->id, $now, $now]);

            return DB::table('migration_invitations')->insertUsing(['migration_run_id', 'client_id', 'user_id', 'status', 'requested_by', 'created_at', 'updated_at'], $eligible);
        });

        if ($queued === 0) {
            throw ValidationException::withMessages(['invitations' => __('No login matches these filters that has not been invited yet.')]);
        }

        $this->audit->log('Client import invitations queued', $run, properties: ['file' => $run->file_name, 'count' => $queued, 'filters' => $filters], event: 'migration.invitations_queued', actor: $actor);
        SendInvitations::next();

        return $queued;
    }
}
