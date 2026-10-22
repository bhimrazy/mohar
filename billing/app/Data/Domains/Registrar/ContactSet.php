<?php

namespace App\Data\Domains\Registrar;

/**
 * Contacts by role; each is a field => value map. Kernel shape (ADR 0009 Amendment 2): the host sends the roles in
 * ROLES, each with exactly the keys in FIELDS. Every value is a string or null; the host guarantees:
 * - `first_name`, `last_name` and `email` are set; `company` and the address fields may be null;
 * - `country` is an uppercase ISO 3166-1 alpha-2 code, or null;
 * - `phone` is what the client typed (free text, at most 50 characters, not normalised): convert it to the
 *   registrar's format (`+977.15550100`, `phone-cc` + `phone`) in the driver.
 */
final readonly class ContactSet
{
    public const ROLES = ['registrant', 'admin', 'tech', 'billing'];

    public const FIELDS = ['first_name', 'last_name', 'company', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];

    /**
     * @param  array<string, array<string, string|null>>  $contacts
     */
    public function __construct(public array $contacts = []) {}

    /**
     * @return array<string, string|null>|null
     */
    public function role(string $role): ?array
    {
        return $this->contacts[$role] ?? null;
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    public function toArray(): array
    {
        return $this->contacts;
    }
}
