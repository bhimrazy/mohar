<?php

namespace App\Modules\Billing\Services\Tax;

use App\Contracts\Billing\TaxCalculator;
use App\Contracts\Core\CompanyClock;
use App\Data\Billing\InvoiceLineData;
use App\Data\Billing\TaxTotals;
use App\Enums\BillableType;
use App\Modules\Billing\Enums\TaxExemptReason;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resolves the applicable tax rates for a customer location and computes document taxes (§5).
 */
class TaxEngine implements TaxCalculator
{
    public function __construct(private BillingSettings $settings) {}

    public function calculate(array $lines, string $currency, ?string $country, ?string $state, bool $taxExempt = false): TaxTotals
    {
        return $this->totals($lines, $currency, $this->resolve($country, $state, $taxExempt));
    }

    public function calculateForClient(array $lines, int $clientId, string $currency): TaxTotals
    {
        return $this->totals($lines, $currency, $this->ratesForClient(Client::query()->findOrFail($clientId)));
    }

    public function ratesForClient(Client $client, ?CarbonImmutable $date = null): TaxRates
    {
        return $this->resolve($client->country, $client->state, $client->tax_exempt, $client->tax_id, $date);
    }

    /**
     * Rates for a customer location. Most specific rule per level wins (country+state > country > all).
     */
    public function resolve(?string $country, ?string $state, bool $taxExempt = false, ?string $vatId = null, ?CarbonImmutable $date = null): TaxRates
    {
        if (! $this->settings->bool('tax.enabled')) {
            return TaxRates::none();
        }

        if ($taxExempt) {
            return TaxRates::none(TaxExemptReason::Exempt);
        }

        $country = $country !== null ? strtoupper($country) : null;

        if ($this->isReverseCharge($country, $vatId)) {
            return TaxRates::none(TaxExemptReason::ReverseCharge);
        }

        $scope = $this->supplyScope($country, $state, $vatId);
        $rules = TaxRule::query()
            ->where(fn ($query) => $query->whereNull('country')->orWhere('country', $country))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', ($date ?? app(CompanyClock::class)->today())->toDateString()))
            ->orderBy('id')
            ->get()
            ->filter(fn (TaxRule $rule): bool => ($rule->state === null || ($state !== null && strcasecmp($rule->state, $state) === 0))
                && ($rule->scope === null || $rule->scope === $scope));

        $level1 = $this->mostSpecific($rules, 1);
        $level2 = $this->mostSpecific($rules, 2);

        return new TaxRates(
            name1: $level1?->name,
            rate1: $level1 !== null ? TaxRates::toMilli($level1->rate) : 0,
            name2: $level2?->name,
            rate2: $level2 !== null ? TaxRates::toMilli($level2->rate) : 0,
            compound: $this->settings->bool('tax.compound'),
            inclusive: $this->settings->string('tax.mode', 'exclusive') === 'inclusive',
            perInvoice: $this->settings->string('tax.calculation', 'per_line') === 'per_invoice',
            rounding: $this->settings->string('tax.rounding', 'half_up'),
            rule1: $level1?->id,
            rule2: $level2?->id,
        );
    }

    /**
     * Intra-state (home_state) or inter-state (other_state) supply for customers in the seller's home country,
     * using the GSTIN state for registered businesses (place of supply). Null outside the home country.
     */
    public function supplyScope(?string $country, ?string $state, ?string $taxId = null): ?string
    {
        $home = strtoupper($this->settings->string('tax.home_country'));

        if ($country === null || $home === '' || strtoupper($country) !== $home) {
            return null;
        }

        $homeState = $home === 'IN' ? IndianStates::normalize($this->settings->string('tax.home_state')) : strtoupper($this->settings->string('tax.home_state'));
        $customerState = $home === 'IN' ? IndianStates::placeOfSupply($state, $taxId) : ($state !== null ? strtoupper($state) : null);

        return $customerState !== null && $customerState === $homeState ? TaxRule::HOME_STATE : TaxRule::OTHER_STATE;
    }

    /**
     * EU B2B customer in another member state with a valid VAT ID → 0% and a reverse-charge note.
     */
    public function isReverseCharge(?string $country, ?string $vatId): bool
    {
        if (! $this->settings->bool('vat.enabled') || ! $this->settings->bool('vat.auto_exempt_on_valid_id')) {
            return false;
        }

        $home = strtoupper($this->settings->string('vat.home_country'));

        return $country !== null && $country !== $home && VatNumber::isValidFormat($vatId, $country);
    }

    /**
     * Whether a line of this type is taxed under the current settings.
     */
    public function isTaxable(?BillableType $type, bool $flag): bool
    {
        if (! $flag) {
            return false;
        }

        $kind = match ($type) {
            BillableType::Service, BillableType::ServiceUpgrade => 'service',
            BillableType::ServiceAddon => 'addon',
            BillableType::ServiceSetup => 'setup',
            BillableType::Domain => 'domain',
            BillableType::LateFee => 'late_fee',
            BillableType::Promotion => null,
            BillableType::AddFunds => $this->settings->bool('credit.tax_add_funds') ? null : 'never',
            BillableType::Manual, null => 'manual',
        };

        // Promotions (negative lines) follow their flag so they reduce the taxable base of the discounted item.
        return $kind === null || in_array($kind, $this->settings->list('tax.apply_to'), true);
    }

    /**
     * @param  list<array{amount: int, taxable: bool, type: BillableType|null}>  $lines
     */
    public function compute(array $lines, TaxRates $rates): TaxResult
    {
        return TaxMath::compute(array_map(fn (array $line): array => [
            'amount' => $line['amount'],
            'taxable' => $this->isTaxable($line['type'], $line['taxable']),
        ], $lines), $rates);
    }

    /**
     * @param  list<InvoiceLineData>  $lines
     */
    private function totals(array $lines, string $currency, TaxRates $rates): TaxTotals
    {
        $result = $this->compute(array_map(fn (InvoiceLineData $line): array => [
            'amount' => $line->total(), 'taxable' => $line->taxable, 'type' => $line->billableType,
        ], $lines), $rates);

        $taxes = [];

        if ($rates->rate1 > 0) {
            $taxes[] = ['name' => (string) $rates->name1, 'rate' => $rates->rate1 / 1000, 'amount' => $result->tax1];
        }

        if ($rates->rate2 > 0) {
            $taxes[] = ['name' => (string) $rates->name2, 'rate' => $rates->rate2 / 1000, 'amount' => $result->tax2];
        }

        return new TaxTotals(strtoupper($currency), $result->subtotal, $taxes, $result->total);
    }

    /**
     * @param  Collection<int, TaxRule>  $rules
     */
    private function mostSpecific(Collection $rules, int $level): ?TaxRule
    {
        return $rules->where('level', $level)
            ->sortBy([
                fn (TaxRule $a, TaxRule $b): int => $b->specificity() <=> $a->specificity(),
                fn (TaxRule $a, TaxRule $b): int => $b->priority <=> $a->priority,
                fn (TaxRule $a, TaxRule $b): int => ($b->effective_from?->getTimestamp() ?? 0) <=> ($a->effective_from?->getTimestamp() ?? 0),
            ])
            ->first();
    }
}
