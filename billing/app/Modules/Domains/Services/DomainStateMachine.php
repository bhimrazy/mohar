<?php

namespace App\Modules\Domains\Services;

use App\Events\Domains\DomainExpired;
use App\Events\Domains\DomainStatusChanged;
use App\Events\Domains\DomainTransferredAway;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\DomainActionNotAllowed;
use App\Modules\Domains\Models\Domain;
use Illuminate\Support\Facades\DB;

/**
 * The only place a domain's status changes (spec §3). Locks the row, enforces the transition table
 * (unless forced by staff), writes the activity log and emits DomainStatusChanged after commit.
 */
class DomainStateMachine
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $attributes  other columns to update in the same write
     *
     * @throws DomainActionNotAllowed
     */
    public function transition(Domain $domain, DomainStatus $to, ?string $reason = null, array $attributes = [], bool $force = false): Domain
    {
        return DB::transaction(function () use ($domain, $to, $reason, $attributes, $force): Domain {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $from = $locked->status;

            if ($from !== $to && ! $force && ! $from->canTransitionTo($to)) {
                throw new DomainActionNotAllowed("A domain cannot move from {$from->label()} to {$to->label()}.");
            }

            $locked->fill($attributes);
            $locked->status = $to;

            if (! $to->isTerminal() && $from->isTerminal() && $this->nameIsHeldElsewhere($locked)) {
                throw new DomainActionNotAllowed("Another domain record already holds {$locked->name}.");
            }

            $locked->save();

            $domain->setRawAttributes($locked->getAttributes(), true);

            if ($from === $to) {
                return $domain;
            }

            $this->activity->log(
                $force ? "Domain status forced from {$from->value} to {$to->value}" : "Domain status changed from {$from->value} to {$to->value}",
                $locked,
                $locked->client_id,
                array_filter(['from' => $from->value, 'to' => $to->value, 'reason' => $reason]),
            );

            DomainEvents::dispatch(new DomainStatusChanged($locked->id, $locked->client_id, $from->value, $to->value, $reason));

            if ($to === DomainStatus::Expired) {
                DomainEvents::dispatch(new DomainExpired($locked->id, $locked->client_id));
            }

            if ($to === DomainStatus::TransferredAway) {
                DomainEvents::dispatch(new DomainTransferredAway($locked->id, $locked->client_id));
            }

            return $domain;
        });
    }

    private function nameIsHeldElsewhere(Domain $domain): bool
    {
        return Domain::query()->where('active_name', $domain->name)->whereKeyNot($domain->id)->lockForUpdate()->exists();
    }
}
