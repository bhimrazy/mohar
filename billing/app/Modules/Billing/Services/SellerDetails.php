<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\Settings;

/**
 * The seller identity printed on invoices (legal name, address, tax registrations). Snapshotted when an invoice is
 * issued so old documents never change when the settings do.
 */
class SellerDetails
{
    public function __construct(
        private BillingSettings $settings,
        private Settings $core,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        $fallback = fn (string $key): ?string => is_string($value = $this->core->get('core.'.$key)) && $value !== '' ? $value : null;
        $value = fn (string $key): ?string => ($text = trim($this->settings->string('seller.'.$key))) !== '' ? $text : null;

        return [
            'legal_name' => $value('legal_name') ?? $fallback('company_name') ?? (string) config('app.name'),
            'address' => $value('address'),
            'email' => $value('email') ?? $fallback('company_email'),
            'phone' => $value('phone'),
            'gstin' => $value('gstin'),
            'pan' => $value('pan'),
            'vat_number' => $value('vat_number'),
            'country' => ($country = strtoupper($this->settings->string('tax.home_country'))) !== '' ? $country : null,
            'state' => ($state = $this->settings->string('tax.home_state')) !== '' ? $state : null,
        ];
    }
}
