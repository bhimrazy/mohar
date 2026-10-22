<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Services\Tax\IndianStates;
use App\Modules\Billing\Services\Tax\VatNumber;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\InvoiceBsDates;
use App\Modules\Core\Support\DateFormats;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Server-rendered PDFs on the private "objects" disk, cached by (invoice, version, locale) (§9).
 */
class InvoicePdf
{
    public function __construct(
        private BillingSettings $settings,
        private SellerDetails $seller,
        private MoneyFormatter $money,
        private GatewayManager $gateways,
        private InvoiceBsDates $bsDates,
    ) {}

    public function path(Invoice $invoice, ?string $locale = null): string
    {
        // A PDF rendered with BS dates is cached apart, so turning the setting on or off never serves the other one.
        return sprintf('billing/invoices/%d/v%d-%s%s.pdf', $invoice->id, $invoice->version, $locale ?? app()->getLocale(), $this->bsDates->enabled() ? '-bs' : '');
    }

    /**
     * Path of the cached PDF, rendering it first when this version hasn't been rendered yet.
     */
    public function ensure(Invoice $invoice, ?string $locale = null): string
    {
        $path = $this->path($invoice, $locale);
        $disk = Storage::disk('objects');

        if (! $disk->exists($path)) {
            $disk->put($path, $this->render($invoice));
        }

        return $path;
    }

    public function render(Invoice $invoice): string
    {
        return Pdf::loadView('billing::pdf.invoice', $this->viewData($invoice))
            ->setPaper(strtolower($this->settings->string('pdf.paper', 'A4')))->output();
    }

    /**
     * Everything the invoice template prints, resolved up front (also used by tests).
     *
     * @return array<string, mixed>
     */
    public function viewData(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'adjustments', 'transactions']);
        $seller = $invoice->seller_snapshot ?? $this->seller->snapshot();
        $buyer = $invoice->client_snapshot ?? [];
        $buyerCountry = is_string($buyer['country'] ?? null) ? strtoupper($buyer['country']) : null;
        $sellerCountry = is_string($seller['country'] ?? null) ? strtoupper($seller['country']) : null;
        $taxed = $invoice->tax1_amount + $invoice->tax2_amount > 0;
        $indianGst = $sellerCountry === 'IN' && $buyerCountry === 'IN';
        $placeOfSupply = null;
        $dateFormat = DateFormats::current();

        if ($indianGst) {
            $state = IndianStates::placeOfSupply(is_string($buyer['state'] ?? null) ? $buyer['state'] : null, is_string($buyer['tax_id'] ?? null) ? $buyer['tax_id'] : null);
            $placeOfSupply = $state !== null ? IndianStates::label($state) : null;
        }

        return [
            'invoice' => $invoice,
            'seller' => $seller,
            'buyer' => $buyer,
            'title' => match (true) {
                $invoice->number === null && $invoice->proforma_number !== null => __('Proforma Invoice'),
                $taxed => __('Tax Invoice'),
                default => __('Invoice'),
            },
            'buyerTaxLabel' => self::taxIdLabel($buyerCountry),
            'placeOfSupply' => $placeOfSupply,
            'showSac' => $sellerCountry === 'IN' && ($indianGst || $taxed) && $invoice->items->contains(fn ($item) => $item->sac_code !== null),
            'money' => fn (int $amount): string => $this->money->format($amount, $invoice->currency),
            'date' => fn (?CarbonInterface $date): string => $date?->format($dateFormat) ?? '—',
            // Issue and due dates: the AD date, and the BS date in brackets when that setting is on.
            'invoiceDate' => fn (?CarbonInterface $date): string => $date === null ? '—' : $date->format($dateFormat).(($bs = $this->bsDates->label($date)) !== null ? ' ('.$bs.')' : ''),
            'bsDates' => $this->bsDates->enabled(),
            'gatewayName' => fn (string $key): string => $this->gateways->has($key) ? $this->gateways->gateway($key)->display_name : ucfirst(str_replace('_', ' ', $key)),
        ];
    }

    public static function taxIdLabel(?string $country): string
    {
        return match (true) {
            $country === 'IN' => 'GSTIN',
            $country === 'NP' => 'PAN/VAT No.',
            VatNumber::isEuCountry($country) => 'VAT ID',
            default => 'Tax ID',
        };
    }

    public function renderQuote(Quote $quote): string
    {
        $quote->loadMissing(['items', 'client']);
        $seller = $this->seller->snapshot();

        return Pdf::loadView('billing::pdf.quote', [
            'quote' => $quote,
            'company' => ['name' => (string) $seller['legal_name'], 'email' => (string) ($seller['email'] ?? '')],
            'money' => fn (int $amount): string => $this->money->format($amount, $quote->currency),
        ])->setPaper(strtolower($this->settings->string('pdf.paper', 'A4')))->output();
    }

    public function filename(Invoice $invoice): string
    {
        return 'invoice-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoice->displayNumber()).'.pdf';
    }
}
