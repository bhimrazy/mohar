<?php

namespace App\Modules\Migration\Actions;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Jobs\ApplyChunk;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\ReviewDigest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Confirming an import (client-import spec §5.6): only the rows staff reviewed (the digest), only when no row still
 * needs a decision, and one import at a time per install, because clients.email is not unique and two imports could
 * create the same person twice. The rows are applied on the queue, ApplyChunk::CHUNK at a time.
 */
class ApplyRun
{
    public function __construct(private ActivityLogger $audit) {}

    public function start(MigrationRun $run, string $digest, User $staff): void
    {
        Cache::lock('migration.apply', 30)->block(10, function () use ($run, $digest, $staff): void {
            $run->refresh();
            $problem = match (true) {
                $run->status !== MigrationRun::READY => __('This import is not ready to apply.'),
                $run->decision_count > 0 => trans_choice('Decide what to do with the row that needs a decision first.|Decide what to do with the :count rows that need a decision first.', $run->decision_count, ['count' => $run->decision_count]),
                MigrationRun::query()->where('status', MigrationRun::APPLYING)->exists() => __('Another import is being applied. Try again when it finishes.'),
                ! hash_equals(ReviewDigest::of($run), $digest) || $run->review_digest !== $digest => __('The rows changed since you reviewed them. Review the import again.'),
                default => null,
            };

            if ($problem !== null) {
                throw ValidationException::withMessages(['apply' => $problem]);
            }

            $run->forceFill(['status' => MigrationRun::APPLYING, 'applied_by' => $staff->id, 'applied_at' => now(), 'heartbeat_at' => now(), 'error' => null])->save();
        });

        $this->audit->log('Client import confirmed', $run, properties: ['file' => $run->file_name, 'create' => $run->create_count, 'update' => $run->update_count, 'tax_exempt' => $run->tax_exempt_count], event: 'migration.confirmed', actor: $staff);
        dispatch(new ApplyChunk($run->id));
    }
}
