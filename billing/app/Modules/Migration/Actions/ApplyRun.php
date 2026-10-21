<?php

namespace App\Modules\Migration\Actions;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Jobs\ApplyChunk;
use App\Modules\Migration\Models\MigrationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starting and continuing an import (client-import spec §5.6), the one place that enforces one import at a time per
 * install: clients.email is not unique, so two imports could create the same person twice. A run stopped partway
 * (failed, with rows applied) still holds the slot until it is continued. Every check runs under row locks on the runs
 * that could take the slot, in id order, the same locks that staff decisions and checks take on their run.
 */
class ApplyRun
{
    public function __construct(private ActivityLogger $audit) {}

    /**
     * Confirm the reviewed rows: only with the digest the page showed and no row left needing a decision.
     */
    public function start(MigrationRun $run, string $digest, User $staff): void
    {
        $this->claim($run, function (MigrationRun $locked) use ($digest): ?string {
            return match (true) {
                $locked->status !== MigrationRun::READY => (string) __('This import is not ready to apply.'),
                $locked->decision_count > 0 => trans_choice('Decide what to do with the row that needs a decision first.|Decide what to do with the :count rows that need a decision first.', $locked->decision_count, ['count' => $locked->decision_count]),
                ! hash_equals((string) $locked->review_digest, $digest) => (string) __('The rows changed since you reviewed them. Review the import again.'),
                default => null,
            };
        }, ['applied_by' => $staff->id, 'applied_at' => now()]);

        $this->audit->log('Client import confirmed', $run, properties: ['file' => $run->file_name, 'create' => $run->create_count, 'update' => $run->update_count, 'tax_exempt' => $run->tax_exempt_count], event: 'migration.confirmed', actor: $staff);
        dispatch(new ApplyChunk($run->id));
    }

    /**
     * Continue an import that stopped or stalled while applying; rows already applied are skipped.
     */
    public function resume(MigrationRun $run): void
    {
        $this->claim($run, fn (MigrationRun $locked): ?string => match (true) {
            $locked->applied_at === null || $locked->purged_at !== null => (string) __('This import cannot be continued.'),
            $locked->status === MigrationRun::FAILED, $locked->isStalled() => null,
            default => (string) __('This import is not stopped.'),
        }, []);

        dispatch(new ApplyChunk($run->id));
    }

    /**
     * @param  \Closure(MigrationRun): ?string  $refusal
     * @param  array<string, mixed>  $attributes
     */
    private function claim(MigrationRun $run, \Closure $refusal, array $attributes): void
    {
        DB::transaction(function () use ($run, $refusal, $attributes): void {
            $candidates = MigrationRun::query()->whereIn('status', [MigrationRun::READY, MigrationRun::APPLYING, MigrationRun::FAILED])
                ->orWhereKey($run->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = $candidates->get($run->id) ?? throw ValidationException::withMessages(['apply' => __('This import no longer exists.')]);
            $busy = $candidates->contains(fn (MigrationRun $other): bool => $other->id !== $run->id
                && ($other->status === MigrationRun::APPLYING || ($other->status === MigrationRun::FAILED && $other->applied_at !== null && $other->purged_at === null)));
            $problem = $refusal($locked) ?? ($busy ? __('Another import is being applied or waits to be continued. Finish it first.') : null);

            if ($problem !== null) {
                throw ValidationException::withMessages(['apply' => $problem]);
            }

            $locked->forceFill([...$attributes, 'status' => MigrationRun::APPLYING, 'heartbeat_at' => now(), 'error' => null])->save();
            $run->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
