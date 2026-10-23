<?php

namespace App\Modules\Domains\Actions;

use App\Contracts\Core\CompanyClock;
use App\Contracts\Domains\Registrar\ManagesContacts;
use App\Data\Domains\Registrar\ContactSet;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Events\Domains\DomainContactsUpdated;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainContact;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\RegistrarGateway;
use Illuminate\Support\Facades\DB;

/**
 * Update registrar contacts, then the local cache. Driver flags such as contact_change_pending /
 * transfer_lock_until (from the driver's transfer_lock_days) are kept in registrar_meta for the UI (spec §4.7).
 */
class UpdateContacts
{
    public function __construct(private RegistrarGateway $gateway, private ActivityLogger $activity, private CompanyClock $clock) {}

    /**
     * @param  array<string, array<string, string|null>>  $contacts  role => fields
     */
    public function handle(Domain $domain, array $contacts): Domain
    {
        $contacts = array_intersect_key($contacts, array_flip(DomainContact::ROLES));
        $set = new ContactSet(array_map(fn (array $fields): array => array_intersect_key($fields, array_flip(DomainContact::FIELDS)), $contacts));
        $meta = [];

        if ($domain->registrar_key !== null) {
            $result = $this->gateway->forDomain($domain, 'contacts', ManagesContacts::class, fn (ManagesContacts $registrar, RegistrarConfig $config): RegistrarResult => $registrar->setContacts($config, $domain->toRef(), $set), ['contacts' => $set->toArray()]);
            $meta = array_intersect_key($result->meta, array_flip(['contact_change_pending']));

            // The driver says how long the lock lasts; the date is the company's (ADR 0013).
            if (is_int($result->meta['transfer_lock_days'] ?? null)) {
                $meta['transfer_lock_until'] = $this->clock->todayDate()->addDays($result->meta['transfer_lock_days'])->toDateString();
            }
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
