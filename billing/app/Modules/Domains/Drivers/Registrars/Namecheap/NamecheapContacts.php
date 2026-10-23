<?php

namespace App\Modules\Domains\Drivers\Registrars\Namecheap;

use App\Data\Domains\Registrar\ContactSet;
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

    /** Calling codes for numbers typed without one, by ISO country; anything else must be typed with its +code. */
    private const CALLING_CODES = ['NP' => '977', 'IN' => '91', 'BD' => '880', 'BT' => '975', 'LK' => '94', 'PK' => '92', 'CN' => '86', 'AE' => '971', 'SG' => '65', 'US' => '1', 'CA' => '1', 'GB' => '44', 'AU' => '61', 'NZ' => '64', 'DE' => '49', 'FR' => '33'];

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
     * A typed phone number as Namecheap's `+NNN.NNNN`: `+977.15550100`, `+977 1-5550100`, or a local `01-5550100`
     * with the contact's country.
     */
    public static function phone(string $typed, string $country): string
    {
        $typed = trim($typed);

        if (preg_match('/^\+(\d{1,3})[\s.\-]+(.+)$/', $typed, $match) === 1) {
            return self::checked($match[1], (string) preg_replace('/\D/', '', $match[2]));
        }

        // `+9779841234567`: no separator, so the country code is the longest known one the digits start with.
        if (str_starts_with($typed, '+')) {
            $digits = (string) preg_replace('/\D/', '', $typed);

            foreach ([3, 2, 1] as $length) {
                if (in_array(substr($digits, 0, $length), self::CALLING_CODES, true)) {
                    return self::checked(substr($digits, 0, $length), substr($digits, $length));
                }
            }

            throw new RegistrarException('The contact phone number\'s country code is not recognised; write it with a dot after the code, for example +977.15550100.');
        }

        $code = self::CALLING_CODES[$country] ?? throw new RegistrarException('Enter the contact phone number with its country code, for example +977.15550100.');

        return self::checked($code, ltrim((string) preg_replace('/\D/', '', $typed), '0'));
    }

    private static function checked(string $code, string $number): string
    {
        if (strlen($number) < 4 || strlen($number) > 14) {
            throw new RegistrarException('The contact phone number is not a valid length; enter it with its country code, for example +977.15550100.');
        }

        return '+'.$code.'.'.$number;
    }
}
