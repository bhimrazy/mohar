<?php

namespace App\Modules\Platform\Actions\Clients;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Merge a source client into a target in one transaction: every row owned by the source
 * (any table with a client_id column, across modules) moves to the target, source users
 * become non-owner users of the target, and the source is closed as a tombstone.
 */
class MergeClients
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * Row counts that would move, per table.
     *
     * @return array<string, int>
     */
    public function preview(Client $source): array
    {
        $counts = [];

        foreach ($this->tables() as $table) {
            $count = DB::table($table)->where('client_id', $source->id)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        $counts['client_user'] = DB::table('client_user')->where('client_id', $source->id)->count();

        return array_filter($counts);
    }

    /**
     * @return array<string, int> moved rows per table
     *
     * @throws ValidationException
     */
    public function handle(Client $source, Client $target): array
    {
        $this->validate($source, $target);

        $moved = DB::transaction(function () use ($source, $target): array {
            $moved = [];

            foreach ($this->tables() as $table) {
                $count = DB::table($table)->where('client_id', $source->id)->update(['client_id' => $target->id]);

                if ($count > 0) {
                    $moved[$table] = $count;
                }
            }

            $moved['client_user'] = $this->moveUsers($source, $target);

            $source->forceFill(['status' => 'closed', 'closed_at' => now(), 'merged_into_client_id' => $target->id])->save();

            return array_filter($moved);
        });

        $this->activity->log("Client #{$source->id} merged into #{$target->id}", $target, $target->id, ['source_id' => $source->id, 'moved' => $moved], 'client.merged');
        $this->activity->log("Client merged into #{$target->id}", $source, $source->id, ['target_id' => $target->id], 'client.merged');

        return $moved;
    }

    /**
     * @throws ValidationException
     */
    private function validate(Client $source, Client $target): void
    {
        $error = match (true) {
            $source->is($target) => __('Choose a different client to merge into.'),
            $source->merged_into_client_id !== null || $target->merged_into_client_id !== null => __('Merged clients cannot be merged again.'),
            $target->status === 'closed' => __('Reopen the target client before merging into it.'),
            $source->currency !== $target->currency => __('Both clients must use the same currency (:source vs :target).', ['source' => $source->currency, 'target' => $target->currency]),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['target_id' => $error]);
        }
    }

    private function moveUsers(Client $source, Client $target): int
    {
        $moved = 0;
        $existing = DB::table('client_user')->where('client_id', $target->id)->pluck('user_id')->all();

        foreach (DB::table('client_user')->where('client_id', $source->id)->get() as $row) {
            if (! in_array($row->user_id, $existing, true)) {
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

    /**
     * Tables (from every module) holding client-owned rows.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $excluded = (array) config('platform.merge_excluded_tables', []);

        return array_values(array_filter(
            array_map(fn (array $table): string => $table['name'], Schema::getTables()),
            fn (string $table): bool => ! in_array($table, $excluded, true) && Schema::hasColumn($table, 'client_id'),
        ));
    }
}
