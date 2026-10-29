<?php

namespace App\Contracts\Domains;

/**
 * Registry naming rules for a domain name, applied to every order whoever places it. Implemented by the Domains
 * module; without it nothing is restricted.
 */
interface DomainNameRules
{
    /**
     * Why the name cannot exist under its registry's rules (e.g. "asha.np", where .np registers only under its
     * second levels), as a message to show the person ordering, or null when it is acceptable.
     */
    public function registryLevelError(string $domain): ?string;

    /**
     * Whether the extension (".np") is one clients register at the registry themselves, so it is never sold here.
     */
    public function registeredByClient(string $tld): bool;
}
