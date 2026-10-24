<?php

namespace App\Modules\Domains\Listeners;

use App\Events\Core\ClientMerged;
use App\Modules\Domains\Models\RegistrarRefRecord;
use Illuminate\Support\Facades\DB;

/**
 * Domains (and their reminders) and registrar customer and contact refs follow the merged client, in that order. Where the target already has the same ref (same
 * registrar, type and payload), the target's is kept and the source's is dropped, so one client never maps to two
 * remote customers. Runs synchronously inside the merge transaction.
 */
class MoveRegistrarRefsOnClientMerge
{
    /** Drivers store customer and contact refs against the client (local_type 'client'). */
    public const LOCAL_TYPE = 'client';

    public function handle(ClientMerged $event): void
    {
        // The domains move with their refs: drivers look a customer up by the domain's client.
        DB::table('domains')->where('client_id', $event->sourceClientId)->update(['client_id' => $event->targetClientId, 'updated_at' => now()]);
        DB::table('domain_reminders')->where('client_id', $event->sourceClientId)->update(['client_id' => $event->targetClientId]);

        RegistrarRefRecord::query()
            ->where('local_type', self::LOCAL_TYPE)->where('local_id', $event->sourceClientId)
            ->orderBy('id')->lockForUpdate()
            ->get()
            ->each(function (RegistrarRefRecord $ref) use ($event): void {
                $targetHasIt = RegistrarRefRecord::query()->where([
                    'registrar_key' => $ref->registrar_key, 'ref_type' => $ref->ref_type, 'local_type' => self::LOCAL_TYPE,
                    'local_id' => $event->targetClientId, 'payload_hash' => $ref->payload_hash,
                ])->exists();

                $targetHasIt ? $ref->delete() : $ref->update(['local_id' => $event->targetClientId]);
            });
    }
}
