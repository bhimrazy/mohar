<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Server-rendered PDFs on the private "objects" disk, cached by (invoice, version, locale) (§9).
 */
class InvoicePdf
{
    public function __construct(
        private BillingSettings $settings,
        private Settings $core,
    ) {}

    public function path(Invoice $invoice, ?string $locale = null): string
    {
        return sprintf('billing/invoices/%d/v%d-%s.pdf', $invoice->id, $invoice->version, $locale ?? app()->getLocale());
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
        $invoice->loadMissing(['items', 'adjustments', 'transactions']);

        return Pdf::loadView('billing::pdf.invoice', [
            'invoice' => $invoice,
            'company' => $this->company(),
        ])->setPaper(strtolower($this->settings->string('pdf.paper', 'A4')))->output();
    }

    public function renderQuote(Quote $quote): string
    {
        $quote->loadMissing(['items', 'client']);

        return Pdf::loadView('billing::pdf.quote', [
            'quote' => $quote,
            'company' => $this->company(),
        ])->setPaper(strtolower($this->settings->string('pdf.paper', 'A4')))->output();
    }

    public function filename(Invoice $invoice): string
    {
        return 'invoice-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoice->displayNumber()).'.pdf';
    }

    /**
     * @return array{name: string, email: string}
     */
    private function company(): array
    {
        $name = $this->core->get('core.company_name', config('app.name'));
        $email = $this->core->get('core.company_email', '');

        return ['name' => is_string($name) ? $name : '', 'email' => is_string($email) ? $email : ''];
    }
}
