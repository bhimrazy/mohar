<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\DateFormats;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Localizable;

/**
 * The PDF of a credit or debit note (vat-register §8, AC 36). A note never changes, so the original is rendered once per
 * locale and cached on the private "objects" disk; a copy (print 2 and later) is rendered each time with its print number.
 */
class TaxNotePdf
{
    use Localizable;

    public function __construct(private BillingSettings $settings, private MoneyFormatter $money) {}

    public function path(TaxNote $note, ?string $locale = null): string
    {
        return sprintf('billing/tax-notes/%d/original-%s.pdf', $note->id, $locale ?? app()->getLocale());
    }

    /**
     * Path of the cached original in `$locale` (the request's when null), rendering it first when needed.
     */
    public function ensure(TaxNote $note, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $path = $this->path($note, $locale);
        $disk = Storage::disk('objects');

        if (! $disk->exists($path)) {
            $disk->put($path, $this->withLocale($locale, fn (): string => $this->render($note)));
        }

        return $path;
    }

    /**
     * @param  int|null  $print  the print number of a copy (2 or more); null for the original
     */
    public function render(TaxNote $note, ?int $print = null): string
    {
        return Pdf::loadView('billing::pdf.tax-note', $this->viewData($note, $print))
            ->setPaper(strtolower($this->settings->string('pdf.paper', 'A4')))->output();
    }

    /**
     * Everything the note template prints, from the note's own snapshots (also used by tests).
     *
     * @return array<string, mixed>
     */
    public function viewData(TaxNote $note, ?int $print = null): array
    {
        $note->loadMissing(['lines', 'invoice:id,issue_date,tax1_name,tax1_rate,tax2_name,tax2_rate']);
        $dateFormat = DateFormats::current();
        $rate = fn (string $value): string => rtrim(rtrim($value, '0'), '.');
        $invoice = $note->invoice;

        return [
            'note' => $note,
            'title' => $note->kindLabel(),
            'seller' => is_array($note->seller_snapshot) ? InvoiceParties::sellerBlock($note->seller_snapshot) : null,
            'buyer' => is_array($note->buyer_snapshot) ? InvoiceParties::buyerBlock($note->buyer_snapshot) : null,
            'issued' => $this->date($note->issue_date, $dateFormat, TaxNote::bsLabel($note->bs_issue_date)),
            'invoiceDate' => $this->date($invoice->issue_date, $dateFormat, TaxNote::bsLabel($note->invoice_bs_issue_date)),
            'taxes' => array_values(array_filter([
                $note->tax1_amount !== 0 ? ['label' => ($invoice->tax1_name ?? __('Tax')).' @ '.$rate($invoice->tax1_rate).'%', 'amount' => $note->tax1_amount] : null,
                $note->tax2_amount !== 0 ? ['label' => ($invoice->tax2_name ?? __('Tax')).' @ '.$rate($invoice->tax2_rate).'%', 'amount' => $note->tax2_amount] : null,
            ])),
            'copy' => $print !== null && $print > 1 ? __('Copy of original — print :n', ['n' => $print]) : null,
            'money' => fn (int $amount): string => $this->money->format($amount, $note->currency),
        ];
    }

    public function filename(TaxNote $note): string
    {
        return ($note->kind === 'credit' ? 'credit-note-' : 'debit-note-').preg_replace('/[^A-Za-z0-9_-]+/', '-', $note->number).'.pdf';
    }

    private function date(?CarbonInterface $date, string $format, ?string $bs): string
    {
        return $date === null ? '—' : $date->format($format).($bs !== null ? ' ('.$bs.')' : '');
    }
}
