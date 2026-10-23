<?php

namespace App\Modules\Billing\Install;

use App\Contracts\Install\InstallsReferenceData;
use App\Data\Install\ReferenceDataResult;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Support\Settings;

/**
 * Tax and invoice defaults for a first install, following the country the Core installer stored (the marker setting
 * `core.regional_defaults`, written only on a first install). The seller's tax country becomes that country. For
 * Nepal it also adds a VAT rule of 13% and numbers invoices INV-00001 and notes CN-00001 and DN-00001. Tax itself stays off until staff turn it on in
 * Settings → Billing → Tax: charging VAT depends on the company's registration. An installation without the marker,
 * or one that already stores a tax country, is left alone, and a rule staff deleted is not created again.
 */
final class RegionalTaxDefaults implements InstallsReferenceData
{
    public const KEY = 'billing_regional_tax';

    private const MARKER = 'core.regional_defaults';

    public function __construct(private Settings $settings) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Tax and invoice defaults';
    }

    public function install(): ReferenceDataResult
    {
        $country = $this->settings->all()[self::MARKER] ?? null;

        if (! is_string($country) || $country === '' || array_key_exists('billing.tax.home_country', $this->settings->all())) {
            return new ReferenceDataResult(unchanged: 1);
        }

        $values = ['billing.tax.home_country' => $country, 'billing.tax.home_state' => ''];
        $created = 1;

        if ($country === 'NP') {
            // Credit and debit notes pad like the invoices (CN-00001 beside INV-00001).
            $values += ['billing.number_format' => 'INV-{NUMBER}', 'billing.number_padding' => 5, 'billing.credit_note_padding' => 5, 'billing.debit_note_padding' => 5];
            TaxRule::query()->firstOrCreate(
                ['name' => 'VAT', 'country' => 'NP', 'level' => 1],
                ['scope' => null, 'rate' => '13.000', 'priority' => 0],
            );
            $created++;
        }

        $this->settings->setMany($values);

        return new ReferenceDataResult(created: $created);
    }
}
