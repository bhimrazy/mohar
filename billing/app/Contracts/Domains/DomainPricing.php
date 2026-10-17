<?php

namespace App\Contracts\Domains;

/**
 * Implemented by the Domains module.
 */
interface DomainPricing
{
    /**
     * Price in minor units for the action and term, or null if the TLD/term/currency is not offered.
     *
     * @param  'register'|'transfer'|'renew'  $action
     */
    public function price(string $tld, string $action, int $years, string $currency): ?int;

    /**
     * Split "example.co.uk" into ["example", ".co.uk"] using the configured TLD list; null if unsupported.
     *
     * @return array{0: string, 1: string}|null
     */
    public function split(string $domain): ?array;
}
