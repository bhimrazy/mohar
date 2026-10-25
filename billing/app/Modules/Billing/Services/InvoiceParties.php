<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\Tax\IndianStates;
use App\Modules\Billing\Services\Tax\VatNumber;

/**
 * Who an invoice is from and to, for the invoice pages and the PDF alike. The buyer comes only from the snapshot taken
 * when the invoice was written (frozen with its number). The seller comes from the snapshot taken at publishing; only a
 * draft, which has none yet, shows today's settings.
 */
class InvoiceParties
{
    public function __construct(private SellerDetails $seller) {}

    /**
     * @return array{
     *     document: 'draft'|'tax_invoice'|'proforma'|'invoice',
     *     seller: array{name: string, address: string|null, email: string|null, phone: string|null, tax_ids: list<array{label: string, value: string}>}|null,
     *     seller_from_settings: bool,
     *     buyer: array{name: string, contact: string|null, address: list<string>, email: string|null, tax_id: array{label: string, value: string}|null}|null,
     *     place_of_supply: string|null,
     * }
     */
    public function forInvoice(Invoice $invoice): array
    {
        $seller = $this->sellerSnapshot($invoice);
        $buyer = $invoice->client_snapshot;

        return [
            'document' => self::documentType($invoice),
            'seller' => $seller !== null ? self::sellerBlock($seller) : null,
            'seller_from_settings' => $seller !== null && $invoice->seller_snapshot === null,
            'buyer' => is_array($buyer) ? self::buyerBlock($buyer) : null,
            'place_of_supply' => self::placeOfSupply($seller ?? [], is_array($buyer) ? $buyer : []),
        ];
    }

    /**
     * The seller as stored on the invoice. A draft without one reads today's settings; any other invoice without one
     * gets null, never live company data.
     *
     * @return array<string, mixed>|null
     */
    public function sellerSnapshot(Invoice $invoice): ?array
    {
        return $invoice->seller_snapshot ?? ($invoice->status === InvoiceStatus::Draft ? $this->seller->snapshot() : null);
    }

    /**
     * A draft is not a fiscal document; then a proforma until it has a legal number; a tax invoice when it carries tax;
     * otherwise a plain invoice.
     *
     * @return 'draft'|'tax_invoice'|'proforma'|'invoice'
     */
    public static function documentType(Invoice $invoice): string
    {
        return match (true) {
            $invoice->status === InvoiceStatus::Draft => 'draft',
            $invoice->number === null && $invoice->proforma_number !== null => 'proforma',
            $invoice->tax1_amount + $invoice->tax2_amount > 0 => 'tax_invoice',
            default => 'invoice',
        };
    }

    /**
     * @param  array<string, mixed>  $seller
     * @return array{name: string, address: string|null, email: string|null, phone: string|null, tax_ids: list<array{label: string, value: string}>}
     */
    public static function sellerBlock(array $seller): array
    {
        return [
            'name' => (string) ($seller['legal_name'] ?? ''),
            'address' => self::text($seller['address'] ?? null),
            'email' => self::text($seller['email'] ?? null),
            'phone' => self::text($seller['phone'] ?? null),
            'tax_ids' => self::sellerTaxIds($seller),
        ];
    }

    /**
     * The seller's registrations in print order. A Nepal PAN that is also the VAT number is one "PAN/VAT No." line.
     *
     * @param  array<string, mixed>  $seller
     * @return list<array{label: string, value: string}>
     */
    public static function sellerTaxIds(array $seller): array
    {
        $gstin = self::text($seller['gstin'] ?? null);
        $pan = self::text($seller['pan'] ?? null);
        $vat = self::text($seller['vat_number'] ?? null);
        $ids = $gstin !== null ? [['label' => 'GSTIN', 'value' => $gstin]] : [];

        if ($pan !== null && $pan === $vat) {
            return [...$ids, ['label' => 'PAN/VAT No.', 'value' => $pan]];
        }

        if ($pan !== null) {
            $ids[] = ['label' => 'PAN', 'value' => $pan];
        }

        if ($vat !== null) {
            $ids[] = ['label' => ($seller['country'] ?? null) === 'NP' && $pan === null ? 'PAN/VAT No.' : 'VAT No.', 'value' => $vat];
        }

        return $ids;
    }

    /**
     * The label of a buyer's tax number in their country.
     */
    public static function taxIdLabel(?string $country): string
    {
        return match (true) {
            $country === 'IN' => 'GSTIN',
            $country === 'NP' => 'PAN/VAT No.',
            VatNumber::isEuCountry($country) => 'VAT ID',
            default => 'Tax ID',
        };
    }

    /**
     * GST place of supply, printed only when seller and buyer are both in India.
     *
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $buyer
     */
    public static function placeOfSupply(array $seller, array $buyer): ?string
    {
        if (self::country($seller) !== 'IN' || self::country($buyer) !== 'IN') {
            return null;
        }

        $state = IndianStates::placeOfSupply(self::text($buyer['state'] ?? null), self::text($buyer['tax_id'] ?? null));

        return $state !== null ? IndianStates::label($state) : null;
    }

    /**
     * "Nepal" for "NP"; the code itself when PHP's intl has no name for it.
     */
    public static function countryName(?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.strtoupper($code), 'en') : false;

        return is_string($name) && $name !== '' && $name !== strtoupper($code) ? $name : $code;
    }

    /**
     * @param  array<string, mixed>  $buyer
     * @return array{name: string, contact: string|null, address: list<string>, email: string|null, tax_id: array{label: string, value: string}|null}
     */
    public static function buyerBlock(array $buyer): array
    {
        $company = self::text($buyer['company'] ?? null);
        $person = self::text($buyer['name'] ?? null);
        $taxId = self::text($buyer['tax_id'] ?? null);
        $region = trim(implode(' ', array_filter([self::text($buyer['state'] ?? null), self::text($buyer['postcode'] ?? null)])));
        $locality = implode(', ', array_filter([self::text($buyer['city'] ?? null), $region !== '' ? $region : null]));

        return [
            'name' => $company ?? $person ?? '',
            'contact' => $company !== null ? $person : null,
            'address' => array_values(array_filter([
                self::text($buyer['address_1'] ?? null),
                self::text($buyer['address_2'] ?? null),
                $locality !== '' ? $locality : null,
                self::countryName(self::country($buyer)) ?: null,
            ])),
            'email' => self::text($buyer['email'] ?? null),
            'tax_id' => $taxId !== null ? ['label' => self::taxIdLabel(self::country($buyer)), 'value' => $taxId] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $party
     */
    private static function country(array $party): ?string
    {
        return self::text($party['country'] ?? null) !== null ? strtoupper((string) $party['country']) : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
