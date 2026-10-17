<?php

namespace App\Modules\Domains\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Drivers\Registrars\Data\ContactSet;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Events\DomainContactsUpdated;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainContact;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\RegistrarGateway;
use Illuminate\Support\Facades\DB;

/**
 * Update registrar contacts, then the local cache. Driver flags such as contact_change_pending /
 * transfer_lock_until are kept in registrar_meta for the UI (spec §4.7).
 */
class UpdateContacts
{
    public function __construct(private RegistrarGateway $gateway, private ActivityLogger $activity) {}

    /**
     * @param  array<string, array<string, string|null>>  $contacts  role => fields
     */
    public function handle(Domain $domain, array $contacts): Domain
    {
        $contacts = array_intersect_key($contacts, array_flip(DomainContact::ROLES));
        $set = new ContactSet(array_map(fn (array $fields): array => array_intersect_key($fields, array_flip(DomainContact::FIELDS)), $contacts));
        $meta = [];

        if ($domain->registrar_key !== null) {
            $result = $this->gateway->forDomain($domain, 'contacts', fn (Registrar $registrar): RegistrarResult => $registrar->setContacts($domain, $set), ['contacts' => $set->toArray()]);
            $meta = array_intersect_key($result->meta, array_flip(['contact_change_pending', 'transfer_lock_until']));
        } else {
            $this->activity->log("Staff task: update contacts of {$domain->name} at the registrar", $domain, $domain->client_id);
        }

        DB::transaction(function () use ($domain, $set, $meta): void {
            foreach ($set->toArray() as $role => $fields) {
                $domain->contacts()->updateOrCreate(['role' => $role], [...$fields, 'synced_at' => now()]);
            }

            if ($meta !== []) {
                $domain->forceFill(['registrar_meta' => [...($domain->registrar_meta ?? []), ...$meta]])->save();
            }
        });

        $this->activity->log('Domain contacts updated', $domain, $domain->client_id, ['roles' => array_keys($set->toArray())]);
        DomainEvents::dispatch(new DomainContactsUpdated($domain->id, $domain->client_id));

        return $domain;
    }
}
