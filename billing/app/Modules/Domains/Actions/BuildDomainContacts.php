<?php

namespace App\Modules\Domains\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\SettingList;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Drivers\Registrars\Data\ContactSet;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainContact;

/**
 * Contacts for a new domain (spec §4.2): registrant = client profile; admin/tech/billing = client
 * profile when domains.use_client_contacts, else domains.default_contact (company details).
 */
class BuildDomainContacts
{
    public function __construct(private Settings $settings) {}

    public function forNewDomain(Domain $domain, Client $client): void
    {
        $profile = [
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'company' => $client->company_name,
            'email' => $client->email,
            'phone' => $client->phone,
            'address_1' => $client->address_1,
            'address_2' => $client->address_2,
            'city' => $client->city,
            'state' => $client->state,
            'postcode' => $client->postcode,
            'country' => $client->country,
        ];

        $useClient = (bool) $this->settings->get('domains.use_client_contacts', true);
        $default = array_intersect_key(SettingList::map($this->settings->get('domains.default_contact', [])), array_flip(DomainContact::FIELDS));

        foreach (DomainContact::ROLES as $role) {
            $details = $role === 'registrant' || $useClient || $default === [] ? $profile : [...array_fill_keys(DomainContact::FIELDS, null), ...$default];
            $domain->contacts()->updateOrCreate(['role' => $role], $details);
        }
    }

    public function contactSet(Domain $domain): ContactSet
    {
        $contacts = [];

        foreach ($domain->contacts()->get() as $contact) {
            $contacts[$contact->role] = $contact->only(DomainContact::FIELDS);
        }

        return new ContactSet($contacts);
    }
}
