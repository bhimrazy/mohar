<?php

namespace App\Contracts\Domains;

/**
 * Implemented by the Domains module.
 */
interface DomainPricing
{
    /**
     * Price in minor units for the action and term, or null if the TLD/term/currency is not offered.
     * When $clientId is given, the client's group price tier applies.
     *
     * @param  'register'|'transfer'|'renew'  $action
     */
    public function price(string $tld, string $action, int $years, string $currency, ?int $clientId = null): ?int;

    /**
     * Year terms offered for the TLD/action in the currency, ascending (e.g. [1, 2, 3, 5, 10]).
     *
     * @param  'register'|'transfer'|'renew'  $action
     * @return list<int>
     */
    public function terms(string $tld, string $action, string $currency): array;

    /**
     * Split "example.co.uk" into ["example", ".co.uk"] using the configured TLD list; null if unsupported.
     *
     * @return array{0: string, 1: string}|null
     */
    public function split(string $domain): ?array;
}
