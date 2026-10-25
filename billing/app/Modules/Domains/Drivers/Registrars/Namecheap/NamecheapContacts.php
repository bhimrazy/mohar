<?php

namespace App\Modules\Domains\Drivers\Registrars\Namecheap;

use App\Data\Domains\Registrar\ContactSet;
use App\Support\CallingCodes;
use App\Support\Domains\RegistrarException;
use SimpleXMLElement;

/**
 * Namecheap's contact shape (registrars.md §4): there are no contact ids, each domain carries its four roles inline as
 * `<Role><Field>` parameters, and the kernel's `billing` role is Namecheap's `AuxBilling`.
 */
final class NamecheapContacts
{
    /** Kernel role => Namecheap role prefix. */
    public const ROLES = ['registrant' => 'Registrant', 'admin' => 'Admin', 'tech' => 'Tech', 'billing' => 'AuxBilling'];

    /**
     * All four roles as request parameters. A role the host did not send is filled from the registrant, as Namecheap
     * needs every role. Empty optional values are left out; the state and postcode Namecheap requires get a
     * placeholder where a country has none (like the ResellerClub driver).
     *
     * @return array<string, string>
     *
     * @throws RegistrarException when a field Namecheap needs is missing
     */
    public function params(ContactSet $contacts): array
    {
        $registrant = $contacts->role('registrant') ?? throw new RegistrarException('The domain has no registrant contact.');
        $params = [];

        foreach (self::ROLES as $role => $prefix) {
            $contact = $contacts->role($role) ?? $registrant;
            $country = strtoupper(trim((string) ($contact['country'] ?? '')));

            foreach (['first_name' => 'FirstName', 'last_name' => 'LastName', 'address_1' => 'Address1', 'city' => 'City', 'email' => 'EmailAddress'] as $field => $name) {
                $value = trim((string) ($contact[$field] ?? ''));

                if ($value === '') {
                    throw new RegistrarException('The '.$role.' contact has no '.str_replace('_', ' ', $field).'; Namecheap needs it. Edit the contact and try again.');
                }

                $params[$prefix.$name] = $value;
            }

            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw new RegistrarException('The '.$role.' contact has no country; Namecheap needs it. Edit the contact and try again.');
            }

            $company = trim((string) ($contact['company'] ?? ''));
            $address2 = trim((string) ($contact['address_2'] ?? ''));
            $state = trim((string) ($contact['state'] ?? ''));
            $postcode = trim((string) ($contact['postcode'] ?? ''));

            $params[$prefix.'Country'] = $country;
            $params[$prefix.'StateProvince'] = $state === '' ? 'Not Applicable' : $state;
            $params[$prefix.'PostalCode'] = $postcode === '' ? '00000' : $postcode;
            $params[$prefix.'Phone'] = self::phone((string) ($contact['phone'] ?? ''), $country);

            if ($company !== '') {
                $params[$prefix.'OrganizationName'] = $company;
            }

            if ($address2 !== '') {
                $params[$prefix.'Address2'] = $address2;
            }
        }

        return $params;
    }

    /**
     * The kernel contact set from a getContacts answer.
     */
    public function contactSet(SimpleXMLElement $result): ContactSet
    {
        $set = [];

        foreach (self::ROLES as $role => $prefix) {
            if (! isset($result->{$prefix})) {
                continue;
            }

            $node = $result->{$prefix};
            $value = fn (string $name): ?string => trim((string) $node->{$name}) === '' ? null : trim((string) $node->{$name});
            $set[$role] = [
                'first_name' => $value('FirstName') ?? '', 'last_name' => $value('LastName') ?? '', 'company' => $value('OrganizationName'), 'email' => $value('EmailAddress') ?? '',
                'phone' => $value('Phone'), 'address_1' => $value('Address1'), 'address_2' => $value('Address2'), 'city' => $value('City'),
                'state' => $value('StateProvince'), 'postcode' => $value('PostalCode'), 'country' => $value('Country') === null ? null : strtoupper((string) $value('Country')),
            ];
        }

        return new ContactSet($set);
    }

    /**
     * A typed phone number as Namecheap's `+NNN.NNNN` (CallingCodes::split()): `+977.15550100`, `+9779841234567`, or a
     * local `01-5550100` with the contact's country. Namecheap takes at most 14 digits after the code;
     * split() already stops there (15 in all), the check keeps the cap if the table's rule changes.
     *
     * @throws RegistrarException
     */
    public static function phone(string $typed, string $country): string
    {
        $split = CallingCodes::split($typed, $country);

        if ($split === null) {
            if (preg_match('/^\s*(\+|00)/', $typed) === 1) {
                throw new RegistrarException('The contact phone number\'s country code is not recognised or the number has no valid length; write it with its country code, for example +977.15550100.');
            }

            throw new RegistrarException('Enter the contact phone number with its country code, for example +977.15550100.');
        }

        if (strlen($split['national']) > 14) {
            throw new RegistrarException('The contact phone number is not a valid length; enter it with its country code, for example +977.15550100.');
        }

        return '+'.$split['code'].'.'.$split['national'];
    }
}
