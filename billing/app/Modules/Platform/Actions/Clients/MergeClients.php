<?php

namespace App\Modules\Platform\Actions\Clients;

use App\Events\Core\ClientMerged;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use App\Support\Clients\ClientMergeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merge a source client into a target (spec §11).
 *
 * Core moves only the rows it owns (contacts, logins), closes the source as a tombstone and dispatches
 * ClientMerged inside the transaction; every other module re-points and reconciles its own tables in a
 * synchronous listener, so any failure rolls the whole merge back. Modules can veto a merge (e.g. both
 * clients are affiliates with ledger entries) through ClientMergeRegistry.
 */
class MergeClients
{
    public function __construct(private ActivityLogger $activity, private ClientMergeRegistry $participants) {}

    /**
     * Why the merge is not allowed, or null.
     */
    public function problem(Client $source, ?Client $target): ?string
    {
        $problem = match (true) {
            $target === null => __('No client with that ID.'),
            $source->is($target) => __('Choose a different client to merge into.'),
            $source->merged_into_client_id !== null || $target->merged_into_client_id !== null => __('Merged clients cannot be merged again.'),
            $source->status === 'closed' => __('Reopen this client before merging it.'),
            $target->status === 'closed' => __('Reopen the target client before merging into it.'),
            $source->currency !== $target->currency => __('Both clients must use the same currency (:source vs :target).', ['source' => $source->currency, 'target' => $target->currency]),
            default => $this->participants->blockers($source->id, $target->id)[0] ?? null,
        };

        return is_string($problem) ? $problem : null;
    }

    /**
     * What would move, per participant (Core's contacts and logins plus every module's records).
     *
     * @return list<array{key: string, label: string, count: int, blocking: string|null}>
     */
    public function preview(Client $source, Client $target): array
    {
        return $this->participants->preview($source->id, $target->id);
    }

    /**
     * @throws ValidationException
     */
    public function handle(Client $source, Client $target): void
    {
        DB::transaction(function () use ($source, $target): void {
            // Lock both rows (in id order) so concurrent A→B / B→A merges cannot cross.
            $locked = Client::query()->whereKey([$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked->get($source->id) ?? $source;
            $target = $locked->get($target->id);

            $problem = $this->problem($source, $target);

            if ($problem !== null || $target === null) {
                throw ValidationException::withMessages(['target_id' => $problem ?? __('No client with that ID.')]);
            }

            DB::table('contacts')->where('client_id', $source->id)->update(['client_id' => $target->id, 'updated_at' => now()]);
            $logins = $this->moveLogins($source, $target);

            $source->forceFill(['status' => 'closed', 'closed_at' => now(), 'merged_into_client_id' => $target->id])->save();

            $this->activity->log('Another client was merged into this client', $target, $target->id, ['source_id' => $source->id, 'logins_added' => $logins], 'client.merged');
            $this->activity->log('Client merged into another client', $source, $source->id, ['target_id' => $target->id], 'client.merged');

            ClientMerged::dispatch($source->id, $target->id);
        });
    }

    /**
     * Source logins become non-owner users of the target (the former owner keeps full access).
     */
    private function moveLogins(Client $source, Client $target): int
    {
        $moved = 0;
        $existing = DB::table('client_user')->where('client_id', $target->id)->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach (DB::table('client_user')->where('client_id', $source->id)->orderBy('id')->get() as $row) {
            if (! in_array((int) $row->user_id, $existing, true)) {
                $target->users()->attach($row->user_id, [
                    'is_owner' => false,
                    'permissions' => json_encode($row->is_owner ? ClientContext::AREAS : (json_decode((string) $row->permissions, true) ?: [])),
                ]);
                $moved++;
            }
        }

        DB::table('client_user')->where('client_id', $source->id)->delete();

        return $moved;
    }
}
