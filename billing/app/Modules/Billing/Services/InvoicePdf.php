<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\InvoiceBsDates;
use App\Modules\Core\Support\DateFormats;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Localizable;

/**
 * Server-rendered PDFs on the private "objects" disk, cached by (invoice, version, locale) (§9).
 */
class InvoicePdf
{
    use Localizable;

    public function __construct(
        private BillingSettings $settings,
        private SellerDetails $seller,
        private InvoiceParties $parties,
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
        $locale ??= app()->getLocale();
        $path = $this->path($invoice, $locale);
        $disk = Storage::disk('objects');

        if (! $disk->exists($path)) {
            $disk->put($path, $this->withLocale($locale, fn (): string => $this->render($invoice)));
        }

        return $path;
    }

    /**
     * Path of the quote's PDF in `$locale`, rendering it first when this edit of the quote has none. The path carries
     * the quote's `updated_at`, so an edit gets a new file; the older ones are removed.
     */
    public function ensureQuote(Quote $quote, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $directory = 'billing/quotes/'.$quote->id;
        $path = sprintf('%s/%d-%s.pdf', $directory, $quote->updated_at?->getTimestamp() ?? 0, $locale);
        $disk = Storage::disk('objects');

        if (! $disk->exists($path)) {
            $disk->put($path, $this->withLocale($locale, fn (): string => $this->renderQuote($quote)));
            $disk->delete(array_values(array_filter($disk->files($directory), fn (string $file): bool => $file !== $path && str_ends_with($file, '-'.$locale.'.pdf'))));
        }

        return $path;
    }

    public function quoteFilename(Quote $quote): string
    {
        return 'quote-'.trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $quote->displayNumber()), '-').'.pdf';
    }

    /**
     * @param  int|null  $print  the print number of a copy (2 or more, DocumentDownloads); null for the original
     */
    public function render(Invoice $invoice, ?int $print = null): string
    {
        return Pdf::loadView('billing::pdf.invoice', $this->viewData($invoice, $print))
            ->setPaper(strtolower($this->settings->string('pdf.paper', 'A4')))->output();
    }

    /**
     * Everything the invoice template prints, resolved up front (also used by tests).
     *
     * @return array<string, mixed>
     */
    public function viewData(Invoice $invoice, ?int $print = null): array
    {
        $invoice->loadMissing(['items', 'adjustments', 'transactions']);
        $seller = $this->parties->sellerSnapshot($invoice);
        $buyer = $invoice->client_snapshot;
        $sellerCountry = is_string($seller['country'] ?? null) ? strtoupper($seller['country']) : null;
        $buyerCountry = is_string($buyer['country'] ?? null) ? strtoupper($buyer['country']) : null;
        $taxed = $invoice->tax1_amount + $invoice->tax2_amount > 0;
        $dateFormat = DateFormats::current();

        return [
            'invoice' => $invoice,
            'copy' => $print !== null && $print > 1 ? __('Copy of original — print :n', ['n' => $print]) : null,
            // The same blocks the invoice pages show (InvoiceParties), so the page and the PDF print one thing.
            'seller' => $seller !== null ? InvoiceParties::sellerBlock($seller) : null,
            'buyer' => is_array($buyer) ? InvoiceParties::buyerBlock($buyer) : null,
            'title' => match (InvoiceParties::documentType($invoice)) {
                'draft' => __('Draft Invoice'),
                'proforma' => __('Proforma Invoice'),
                'tax_invoice' => __('Tax Invoice'),
                default => __('Invoice'),
            },
            'placeOfSupply' => InvoiceParties::placeOfSupply($seller ?? [], is_array($buyer) ? $buyer : []),
            'showSac' => $sellerCountry === 'IN' && ($buyerCountry === 'IN' || $taxed) && $invoice->items->contains(fn ($item) => $item->sac_code !== null),
            'money' => fn (int $amount): string => $this->money->format($amount, $invoice->currency),
            'date' => fn (?CarbonInterface $date): string => $date?->format($dateFormat) ?? '—',
            // Issue and due dates: the AD date, and the BS date in brackets when that setting is on.
            'invoiceDate' => fn (?CarbonInterface $date): string => $date === null ? '—' : $date->format($dateFormat).(($bs = $this->bsDates->label($date)) !== null ? ' ('.$bs.')' : ''),
            'bsDates' => $this->bsDates->enabled(),
            'gatewayName' => fn (string $key): string => $this->gateways->has($key) ? $this->gateways->gateway($key)->display_name : ucfirst(str_replace('_', ' ', $key)),
        ];
    }

    public function renderQuote(Quote $quote): string
    {
        return Pdf::loadView('billing::pdf.quote', $this->quoteViewData($quote))->setPaper(strtolower($this->settings->string('pdf.paper', 'A4')))->output();
    }

    /**
     * @return array<string, mixed>
     */
    public function quoteViewData(Quote $quote): array
    {
        $quote->loadMissing(['items', 'client']);
        $seller = $this->seller->snapshot();
        $dateFormat = DateFormats::current();

        return [
            'quote' => $quote,
            'company' => ['name' => (string) $seller['legal_name'], 'email' => (string) ($seller['email'] ?? '')],
            'money' => fn (int $amount): string => $this->money->format($amount, $quote->currency),
            'date' => fn (?CarbonInterface $date): string => $date?->format($dateFormat) ?? '—',
        ];
    }

    public function filename(Invoice $invoice): string
    {
        return 'invoice-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoice->displayNumber()).'.pdf';
    }
}
