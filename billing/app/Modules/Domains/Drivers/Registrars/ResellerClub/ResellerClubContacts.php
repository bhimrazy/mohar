<?php

namespace App\Modules\Domains\Drivers\Registrars\ResellerClub;

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\ContactSet;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarRef;
use App\Support\Domains\RegistrarException;

/**
 * ResellerClub's customer → contacts model (registrars.md §3): which customer an order belongs to, and the four
 * contact ids an order needs, created once per distinct contact and remembered in RegistrarRefs.
 *
 * - `customer_mode` default_customer: every order goes to the reseller's `default_customer_id`.
 * - per_client: one customer per client, signed up with a username generated from our client id
 *   (`client-{id}@{host of APP_URL}`, see customerUsername()) and a random password nobody keeps (clients never log in
 *   at ResellerClub). The registrant's email is typed by the client and never verified, so it is only ever a contact's
 *   email, never the customer's username. If the generated username already exists in the account (an earlier signup
 *   whose answer was lost), that customer is adopted only when ResellerClub returns exactly that username.
 * - A contact is keyed by the client and a hash of what was sent (payload_hash), so the same details reuse the same
 *   contact and changed details make a new one; a contact other orders use is never edited.
 * - Roles a TLD does not have are sent as -1; TLDs with their own contact type get it.
 */
final class ResellerClubContacts
{
    /** Roles each registry lacks, by the extension's last label (the API reference's -1 lists). */
    private const ABSENT_ROLES = [
        'uk' => ['admin', 'tech', 'billing'], 'eu' => ['admin', 'tech', 'billing'], 'ru' => ['admin', 'tech', 'billing'],
        'fr' => ['tech', 'billing'], 'ca' => ['billing'], 'nl' => ['billing'], 'nz' => ['billing'], 'berlin' => ['billing'], 'london' => ['billing'],
    ];

    private const CONTACT_TYPES = ['ca' => 'CaContact', 'uk' => 'UkContact', 'eu' => 'EuContact', 'au' => 'AuContact'];

    /** Calling codes for numbers typed without one, by ISO country; anything else must be typed with its +code. */
    private const CALLING_CODES = ['NP' => '977', 'IN' => '91', 'BD' => '880', 'BT' => '975', 'LK' => '94', 'PK' => '92', 'CN' => '86', 'AE' => '971', 'SG' => '65', 'US' => '1', 'CA' => '1', 'GB' => '44', 'AU' => '61', 'NZ' => '64', 'DE' => '49', 'FR' => '33'];

    /** API parameter for each kernel role. */
    public const ROLE_PARAMS = ['registrant' => 'reg-contact-id', 'admin' => 'admin-contact-id', 'tech' => 'tech-contact-id', 'billing' => 'billing-contact-id'];

    public function __construct(private ResellerClubClient $client, private RegistrarRefs $refs) {}

    /**
     * @throws RegistrarException
     */
    public function customerId(RegistrarConfig $config, DomainRef $domain, ContactSet $contacts): string
    {
        if ($config->setting('customer_mode', 'default_customer') !== 'per_client') {
            $id = $config->setting('default_customer_id');

            if (! is_scalar($id) || preg_match('/^\d+$/', (string) $id) !== 1) {
                throw new RegistrarException('Enter the ResellerClub default customer id in the registrar settings, or choose one customer per client.');
            }

            return (string) $id;
        }

        $known = $this->knownCustomerId($config, $domain);

        if ($known !== null) {
            return $known;
        }

        $registrant = $contacts->role('registrant') ?? throw new RegistrarException('The domain has no registrant contact.');
        $username = self::customerUsername($domain->clientId);

        try {
            $id = (string) $this->client->post($config, 'customers/v2/signup.json', [
                ...$this->address($registrant),
                'username' => $username,
                'passwd' => self::password(),
                'name' => self::name($registrant),
                'lang-pref' => 'en',
            ]);
        } catch (RegistrarException $exception) {
            if ($exception->retryable || ! str_contains($exception->getMessage(), 'already exists')) {
                throw $exception;
            }

            $details = (array) $this->client->get($config, 'customers/details.json', ['username' => $username]);

            if (strtolower((string) ($details['username'] ?? '')) !== $username) {
                throw new RegistrarException('ResellerClub has a customer for this client that does not match; link the client by hand.');
            }

            $id = (string) ($details['customerid'] ?? '');
        }

        if (preg_match('/^\d+$/', $id) !== 1) {
            throw new RegistrarException('ResellerClub did not return a customer id.');
        }

        return $this->refs->remember(new RegistrarRef(ResellerClubDriver::KEY, 'customer', 'client', $domain->clientId, $id))->remoteId;
    }

    /**
     * The ResellerClub customer already holding this client's orders, or null when none is known yet: the default
     * customer, or the one remembered for the client. Never creates anything.
     *
     * @throws RegistrarException
     */
    public function knownCustomerId(RegistrarConfig $config, DomainRef $domain): ?string
    {
        if ($config->setting('customer_mode', 'default_customer') !== 'per_client') {
            $id = $config->setting('default_customer_id');

            return is_scalar($id) && preg_match('/^\d+$/', (string) $id) === 1 ? (string) $id : null;
        }

        return $this->refs->find(ResellerClubDriver::KEY, 'customer', 'client', $domain->clientId)?->remoteId;
    }

    /**
     * The username of a client's ResellerClub customer: built from our client id, which only we assign, so no one can
     * pick another client's customer by typing their email address. ResellerClub needs an email-shaped username.
     */
    public static function customerUsername(int $clientId): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        $host = is_string($host) && $host !== '' ? strtolower($host) : 'example';

        return "client-{$clientId}@".(str_contains($host, '.') ? $host : $host.'.invalid');
    }

    /**
     * The four contact parameters of an order, creating contacts ResellerClub does not have yet.
     *
     * @return array<string, string>
     *
     * @throws RegistrarException
     */
    public function contactIds(RegistrarConfig $config, DomainRef $domain, ContactSet $contacts, string $customerId): array
    {
        $label = self::lastLabel($domain->tld);
        $absent = self::ABSENT_ROLES[$label] ?? [];
        $ids = [];

        foreach (self::ROLE_PARAMS as $role => $param) {
            if (in_array($role, $absent, true)) {
                $ids[$param] = '-1';

                continue;
            }

            $contact = $contacts->role($role) ?? $contacts->role('registrant') ?? throw new RegistrarException("The domain has no {$role} contact.");
            $payload = [...$this->address($contact), 'name' => self::name($contact), 'email' => (string) $contact['email'], 'customer-id' => $customerId, 'type' => self::CONTACT_TYPES[$label] ?? 'Contact'];
            unset($payload['state']);
            $payload += ($contact['state'] ?? '') === '' ? [] : ['state' => (string) $contact['state']];
            ksort($payload);
            $hash = hash('sha256', (string) json_encode($payload));
            $ids[$param] = $this->refs->find(ResellerClubDriver::KEY, 'contact', 'client', $domain->clientId, $hash)->remoteId ?? $this->create($config, $domain, $payload, $hash);
        }

        return $ids;
    }

    /**
     * Read an order's contacts back into the kernel shape (ContactSet::FIELDS); a role the TLD lacks is left out.
     *
     * @param  array<string, mixed>  $details  details-by-name with ContactIds
     */
    public function contactSet(RegistrarConfig $config, array $details): ContactSet
    {
        $set = [];

        foreach (['registrant' => 'registrantcontactid', 'admin' => 'admincontactid', 'tech' => 'techcontactid', 'billing' => 'billingcontactid'] as $role => $key) {
            $id = (string) ($details[$key] ?? '');

            if (preg_match('/^\d+$/', $id) !== 1) {
                continue;
            }

            $contact = (array) $this->client->get($config, 'contacts/details.json', ['contact-id' => $id]);
            [$first, $last] = array_pad(explode(' ', trim((string) ($contact['name'] ?? '')), 2), 2, '');
            $company = (string) ($contact['company'] ?? '');
            $set[$role] = [
                'first_name' => $first, 'last_name' => $last, 'company' => in_array($company, ['', 'N A', 'NA', 'N/A'], true) ? null : $company,
                'email' => (string) ($contact['emailaddr'] ?? ''), 'phone' => '+'.($contact['telnocc'] ?? '').'.'.($contact['telno'] ?? ''),
                'address_1' => self::nullable($contact['address1'] ?? null), 'address_2' => self::nullable($contact['address2'] ?? null),
                'city' => self::nullable($contact['city'] ?? null), 'state' => self::nullable($contact['state'] ?? null),
                'postcode' => self::nullable($contact['zip'] ?? null), 'country' => self::nullable($contact['country'] ?? null),
            ];
        }

        return new ContactSet($set);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function create(RegistrarConfig $config, DomainRef $domain, array $payload, string $hash): string
    {
        $id = (string) $this->client->post($config, 'contacts/add.json', $payload);

        if (preg_match('/^\d+$/', $id) !== 1) {
            throw new RegistrarException('ResellerClub did not return a contact id.');
        }

        return $this->refs->remember(new RegistrarRef(ResellerClubDriver::KEY, 'contact', 'client', $domain->clientId, $id, $hash))->remoteId;
    }

    /**
     * The address and phone fields customers and contacts share, with ResellerClub's placeholders for empty ones.
     *
     * @param  array<string, string|null>  $contact
     * @return array<string, string>
     */
    private function address(array $contact): array
    {
        $country = strtoupper((string) ($contact['country'] ?? ''));
        [$code, $number] = self::phone((string) ($contact['phone'] ?? ''), $country);

        return array_filter([
            'company' => trim((string) ($contact['company'] ?? '')) === '' ? 'N A' : (string) $contact['company'],
            'address-line-1' => (string) ($contact['address_1'] ?? ''),
            'address-line-2' => (string) ($contact['address_2'] ?? ''),
            'city' => (string) ($contact['city'] ?? ''),
            'state' => trim((string) ($contact['state'] ?? '')) === '' ? 'Not Applicable' : (string) $contact['state'],
            'country' => $country,
            'zipcode' => trim((string) ($contact['postcode'] ?? '')) === '' ? '00000' : (string) $contact['postcode'],
            'phone-cc' => $code,
            'phone' => $number,
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * A typed phone number as ResellerClub's country code and number: `+977.15550100`, `+977 1-5550100`,
     * `+9779841234567` (no separator: the code is the longest one in CALLING_CODES, the countries mapped here, that the
     * digits start with; a valid code of another country typed without a separator, such as +81…, is refused with an
     * example), or a local `01-5550100` with the contact's country.
     *
     * @return array{0: string, 1: string}
     */
    public static function phone(string $typed, string $country): array
    {
        $typed = trim($typed);

        if (preg_match('/^\+(\d{1,3})[\s.\-]+(.+)$/', $typed, $match) === 1) {
            return [$match[1], (string) preg_replace('/\D/', '', $match[2])];
        }

        if (str_starts_with($typed, '+')) {
            $digits = (string) preg_replace('/\D/', '', $typed);

            foreach ([3, 2, 1] as $length) {
                if (strlen($digits) >= $length && in_array(substr($digits, 0, $length), self::CALLING_CODES, true)) {
                    if (strlen($digits) === $length) {
                        throw new RegistrarException('The contact phone number has a country code but no number; enter it like +977.15550100.');
                    }

                    return [substr($digits, 0, $length), substr($digits, $length)];
                }
            }

            throw new RegistrarException('The contact phone number\'s country code is not recognised; write it with a dot after the code, for example +977.15550100.');
        }

        $digits = (string) preg_replace('/\D/', '', $typed);
        $code = self::CALLING_CODES[$country] ?? throw new RegistrarException('Enter the contact phone number with its country code, for example +977.15550100.');

        return [$code, ltrim($digits, '0')];
    }

    /**
     * @param  array<string, string|null>  $contact
     */
    private static function name(array $contact): string
    {
        return trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? ''));
    }

    private static function lastLabel(string $tld): string
    {
        $labels = explode('.', trim($tld, '.'));

        return strtolower((string) end($labels));
    }

    private static function nullable(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** 12 characters with letters and digits, inside ResellerClub's password rules; never stored. */
    private static function password(): string
    {
        return bin2hex(random_bytes(4)).'Rc'.random_int(10, 99);
    }
}
