<?php

namespace App\Contracts\Billing;

use App\Data\Billing\TaxIdNote;

/**
 * The tax-registration number rules of the countries Billing taxes (client-import spec §6.6): Nepal PAN/VAT numbers,
 * Indian GSTINs and EU VAT ids. Core binds a null default (no rule, no note); Billing binds its rules. No per-row
 * queries (settings are read once and memoised), so an import can run it on thousands of rows.
 */
interface TaxIdRules
{
    /**
     * The number as invoices store it (uppercase without spaces for IN and the EU), for a client in $country.
     */
    public function normalize(string $country, string $taxId): string;

    /**
     * What staff should know about a client's tax number before it is saved: warnings (a format the country does not
     * use, a GSTIN whose state differs from the address) and notes (reverse charge).
     *
     * @param  string|null  $taxId  null or '' when the client has none
     * @param  string|null  $state  the address state or region, as entered
     * @param  bool  $business  the client has a company name
     * @return list<TaxIdNote>
     */
    public function notes(string $country, ?string $taxId, ?string $state, bool $business): array;
}
