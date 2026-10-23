<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Core\CompanyClock;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Support\BikramSambat;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\FiscalSnapshot;
use App\Modules\Billing\Support\FiscalSnapshotCalculator;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OutOfRangeException;

/**
 * Stores the tax-invoice snapshot of an invoice at the moment it gets its legal number (ADR 0024 §2): the value, the
 * reporting rate, the supply class and the Bikram Sambat fields. Called in the numbering transaction, so the values are
 * written once and frozen with the number. The fiscal backfill uses the same attribute rules for invoices numbered before.
 */
class FiscalSnapshotWriter
{
    /** @var array{report: string|null, rates: array<string, string|null>}|null */
    private ?array $memo = null;

    public function __construct(
        private FiscalSnapshotCalculator $calculator,
        private CurrencyConverter $converter,
        private BillingSettings $settings,
        private CompanyClock $clock,
    ) {}

    /**
     * Set the snapshot attributes on an invoice that is being numbered; the caller saves it. Add-funds invoices are
     * deposits, not supplies, and get none. Every value adjustment already on the invoice counts as before numbering,
     * because the caller holds the invoice lock and may have written some in this transaction. `$published` says the
     * invoice was a draft a moment ago, which carries no adjustments, so they are not read.
     */
    public function stamp(Invoice $invoice, CarbonImmutable $at, bool $published = false): void
    {
        if ($invoice->kind === InvoiceKind::AddFunds) {
            return;
        }

        $this->memo = null;

        $snapshot = $this->calculator->compute(
            ['total' => $invoice->total, 'subtotal' => $invoice->subtotal, 'tax1_amount' => $invoice->tax1_amount, 'tax2_amount' => $invoice->tax2_amount, 'tax_inclusive' => $invoice->tax_inclusive],
            $this->itemRows(DB::table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')->get()),
            $published ? [] : $this->adjustmentRows(DB::table('invoice_adjustments')->where('invoice_id', $invoice->id)->whereIn('reason', FiscalSnapshotCalculator::VALUE_REASONS)->orderBy('id')->get()),
        );
        $reviews = $snapshot->reviews;
        $invoice->forceFill($this->attributes($snapshot, $invoice->currency, $invoice->issue_date?->format('Y-m-d'), $at, $reviews));
        $this->record($invoice->id, $reviews);
    }

    /**
     * The columns of the snapshot. `$issueDate` is the date printed on the document, a company-calendar date; without
     * one the company date of `$at` is used. A date outside the Bikram Sambat table leaves the BS fields empty.
     *
     * @param  list<array{reason: string, subject: string, detail: string}>  $reviews  gains the entries this adds
     * @return array<string, mixed>
     */
    public function attributes(FiscalSnapshot $snapshot, string $currency, ?string $issueDate, CarbonInterface $at, array &$reviews): array
    {
        $reportCurrency = $this->memo !== null ? $this->memo['report'] : $this->reportCurrency();
        $rate = null;

        if ($reportCurrency !== null) {
            $rate = $this->memo !== null && array_key_exists($currency, $this->memo['rates']) ? $this->memo['rates'][$currency] : $this->rate($currency, $reportCurrency);

            if ($rate === null) {
                $reviews[] = ['reason' => InvoiceFiscalReview::REPORT_RATE, 'subject' => 'invoice', 'detail' => "No stored exchange rate from {$currency} to {$reportCurrency}."];
            }
        }

        $bs = null;

        try {
            $bs = BikramSambat::fromAd(CarbonImmutable::parse($issueDate ?? $this->clock->localDate($at), 'UTC'));
        } catch (OutOfRangeException) {
            // Outside 2000 to 2095 BS: the AD date alone is stored.
        }

        return [
            'numbered_at' => $at,
            'fiscal_taxable' => $snapshot->taxable,
            'fiscal_exempt' => $snapshot->exempt,
            'fiscal_tax1' => $snapshot->tax1,
            'fiscal_tax2' => $snapshot->tax2,
            'fiscal_total' => $snapshot->total,
            'report_currency' => $reportCurrency,
            'report_rate' => $rate,
            'supply_class' => $snapshot->supplyClass(),
            'bs_issue_date' => $bs?->toIso(),
            'bs_period' => $bs !== null ? BikramSambat::period($bs) : null,
            'bs_fiscal_year' => $bs !== null ? BikramSambat::fiscalYear($bs) : null,
        ];
    }

    /**
     * Resolve the reporting currency and the rates of these currencies once, so a backfill chunk does not repeat the
     * lookups per invoice while its rows are locked. Call it before the chunk's transaction, and flush() after.
     *
     * @param  list<string>  $currencies
     */
    public function warm(array $currencies): void
    {
        $report = $this->reportCurrency();
        $this->memo = ['report' => $report, 'rates' => $report === null ? [] : array_combine($currencies, array_map(fn (string $code): ?string => $this->rate($code, $report), $currencies))];
    }

    public function flush(): void
    {
        $this->memo = null;
    }

    private function rate(string $from, string $to): ?string
    {
        try {
            return $this->converter->rateDecimal($from, $to);
        } catch (BillingException) {
            return null;
        }
    }

    /**
     * Add entries to the staff-review list. An entry already there (same invoice, reason and subject) is kept as it is,
     * so a repeated run neither duplicates it nor un-sees it.
     *
     * @param  list<array{reason: string, subject: string, detail: string}>  $reviews
     */
    public function record(int $invoiceId, array $reviews): void
    {
        if ($reviews === []) {
            return;
        }

        $now = now();
        DB::table('invoice_fiscal_reviews')->insertOrIgnore(array_map(fn (array $review): array => $review + ['invoice_id' => $invoiceId, 'created_at' => $now, 'updated_at' => $now], $reviews));
    }

    /** The reporting currency: `billing.tax.report_currency`, else the default currency; null when neither is set. */
    public function reportCurrency(): ?string
    {
        $code = strtoupper($this->settings->string('tax.report_currency'));

        return $code !== '' ? $code : Currency::query()->where('is_default', true)->value('code');
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{id: int, description: string, amount: int, tax_amount: int, cancelled: bool}>
     */
    public function itemRows(Collection $rows): array
    {
        return array_values($rows->map(fn (\stdClass $row): array => [
            'id' => (int) $row->id, 'description' => (string) $row->description, 'amount' => (int) $row->amount,
            'tax_amount' => (int) $row->tax_amount, 'cancelled' => $row->cancelled_at !== null,
        ])->all());
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{id: int, kind: string, reason: string, amount: int, taxable_amount: int, tax_amount: int, description: string|null}>
     */
    public function adjustmentRows(Collection $rows): array
    {
        return array_values($rows->map(fn (\stdClass $row): array => [
            'id' => (int) $row->id, 'kind' => (string) $row->kind, 'reason' => (string) $row->reason, 'amount' => (int) $row->amount,
            'taxable_amount' => (int) $row->taxable_amount, 'tax_amount' => (int) $row->tax_amount,
            'description' => $row->description !== null ? (string) $row->description : null,
        ])->all());
    }
}
