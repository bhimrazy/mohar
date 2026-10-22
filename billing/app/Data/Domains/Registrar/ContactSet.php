<?php

namespace App\Data\Domains\Registrar;

/**
 * Contacts by role (registrant, admin, tech, billing); each is a field => value map.
 */
final readonly class ContactSet
{
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
