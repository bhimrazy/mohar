<?php

namespace App\Modules\Billing\Services\Tax;

use App\Contracts\Billing\TaxIdRules;
use App\Data\Billing\TaxIdNote;

/**
 * Billing's tax-number rules for other modules (client-import spec §6.6). Every finding is advice for staff, never a
 * refusal: invoices show the number as entered, and tax follows the rules in TaxEngine.
 *
 *  - Nepal: a PAN or VAT number is 9 digits. A business without one is noted, because a VAT invoice to a business
 *    names the buyer's PAN.
 *  - India: a GSTIN has the national format with a known state code; a valid GSTIN registered in another state than
 *    the address decides the place of supply.
 *  - EU: a VAT id with a valid format for another member state than the seller's makes invoices reverse charge when
 *    that setting is on.
 */
class BillingTaxIdRules implements TaxIdRules
{
    private const NEPAL_PAN = '/^\d{9}$/';

    public function __construct(private TaxEngine $engine) {}

    public function normalize(string $country, string $taxId): string
    {
        $country = strtoupper($country);

        return match (true) {
            $country === 'IN', $country === 'NP' => strtoupper((string) preg_replace('/\s+/', '', $taxId)),
            VatNumber::isEuCountry($country) => VatNumber::normalize($taxId),
            default => trim($taxId),
        };
    }

    public function notes(string $country, ?string $taxId, ?string $state, bool $business): array
    {
        $country = strtoupper($country);
        $taxId = $taxId === null || trim($taxId) === '' ? null : $this->normalize($country, $taxId);

        return match (true) {
            $country === 'NP' => $this->nepal($taxId, $business),
            $country === 'IN' => $this->india($taxId, $state),
            $taxId !== null && $this->engine->isReverseCharge($country, $taxId) => [new TaxIdNote(TaxIdNote::INFO, 'reverse_charge',
                __('The VAT ID has a valid format for another EU country, so invoices to this client are reverse charge.'))],
            default => [],
        };
    }

    /**
     * @return list<TaxIdNote>
     */
    private function nepal(?string $pan, bool $business): array
    {
        if ($pan === null) {
            return $business ? [new TaxIdNote(TaxIdNote::INFO, 'pan_missing', __('This business has no PAN or VAT number; VAT invoices to a business show the buyer\'s PAN.'))] : [];
        }

        return preg_match(self::NEPAL_PAN, $pan) === 1 ? [] : [new TaxIdNote(TaxIdNote::WARNING, 'pan_invalid', __('A Nepal PAN or VAT number has 9 digits; invoices will show this one as it is.'))];
    }

    /**
     * @return list<TaxIdNote>
     */
    private function india(?string $gstin, ?string $state): array
    {
        if ($gstin === null) {
            return [];
        }

        if (! IndianStates::isValidGstin($gstin)) {
            return [new TaxIdNote(TaxIdNote::WARNING, 'gstin_invalid', __('The GSTIN is not valid: invoices will show it, but tax uses the address state.'))];
        }

        $registered = IndianStates::stateFromGstin($gstin);
        $address = IndianStates::normalize($state);

        return $registered !== null && $address !== null && $registered !== $address ? [new TaxIdNote(TaxIdNote::WARNING, 'gstin_state',
            __('The GSTIN is registered in :gstin but the address is in :address; the place of supply follows the GSTIN.', [
                'gstin' => IndianStates::label($registered), 'address' => IndianStates::label($address),
            ]))] : [];
    }
}
