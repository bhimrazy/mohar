<?php

namespace App\Modules\Billing\Reports;

use App\Support\Money;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * One tax invoice, credit note or debit note as the registers show it. Amounts are in the register currency, signed
 * (a credit note is negative), and null when the document has no stored rate into that currency. An export's value
 * (supply class `export`) is reported as export value, not as taxable or exempt sales.
 */
final readonly class RegisterDocument
{
    public const KINDS = ['invoice' => 'Tax invoice', 'credit' => 'Credit note', 'debit' => 'Debit note'];

    /**
     * @param  'invoice'|'credit'|'debit'  $kind
     * @param  list<array{description: string, quantity: int}>  $lines
     * @param  list<string>  $settlement  reasons of the adjustments the note wrote
     */
    public function __construct(
        public string $kind,
        public string $number,
        public CarbonImmutable $issueDate,
        public ?string $bsDate,
        public ?string $refNumber,
        public ?string $refBsDate,
        public string $buyer,
        public ?string $pan,
        public bool $business,
        public ?string $country,
        public string $currency,
        public Money $original,
        public ?string $rate,
        public ?int $taxable,
        public ?int $exempt,
        public ?int $tax,
        public ?int $exportValue,
        public string $registerCurrency,
        public array $lines,
        public ?string $reasonCode = null,
        public ?string $reasonText = null,
        public bool $automatic = false,
        public array $settlement = [],
        public ?string $creator = null,
    ) {}

    /**
     * @param  array{lines?: list<array{description: string, quantity: int}>, settlement?: list<string>, creator?: string|null}  $detail
     */
    public static function from(stdClass $row, string $registerCurrency, array $detail): self
    {
        $sign = $row->kind === 'credit' ? -1 : 1;
        $buyer = is_string($row->buyer) ? (array) json_decode($row->buyer, true) : (array) $row->buyer;
        $rate = $row->report_rate === null ? null : (is_float($row->report_rate) ? sprintf('%.12F', $row->report_rate) : (string) $row->report_rate);
        $company = trim((string) ($buyer['company'] ?? ''));
        $pan = trim((string) ($buyer['tax_id'] ?? ''));

        $convert = match (true) {
            $row->report_currency === $registerCurrency && $rate !== null => fn (int $amount): int => RegisterDocuments::convert($sign * $amount, $rate),
            $row->currency === $registerCurrency => fn (int $amount): int => $sign * $amount,
            default => fn (int $amount): ?int => null,
        };
        $export = $row->supply_class === 'export';

        return new self(
            kind: $row->kind,
            number: (string) $row->number,
            issueDate: CarbonImmutable::parse(substr((string) $row->issue_date, 0, 10), 'UTC'),
            bsDate: $row->bs_issue_date,
            refNumber: $row->ref_number,
            refBsDate: $row->ref_bs_date,
            buyer: $company !== '' ? $company : trim((string) ($buyer['name'] ?? '')),
            pan: $pan !== '' ? $pan : null,
            business: $company !== '',
            country: isset($buyer['country']) ? (string) $buyer['country'] : null,
            currency: (string) $row->currency,
            original: Money::of($sign * (int) $row->total, (string) $row->currency),
            rate: $rate,
            taxable: $export ? 0 : $convert((int) $row->taxable),
            exempt: $export ? 0 : $convert((int) $row->exempt),
            tax: $convert((int) $row->tax),
            exportValue: $export ? $convert((int) $row->taxable + (int) $row->exempt) : 0,
            registerCurrency: $registerCurrency,
            lines: $detail['lines'] ?? [],
            reasonCode: $row->reason_code,
            reasonText: $row->reason_text,
            automatic: (bool) $row->automatic,
            settlement: $detail['settlement'] ?? [],
            creator: $detail['creator'] ?? null,
        );
    }

    /** False when the document has no rate into the register currency: its amounts are left out of the totals. */
    public function converted(): bool
    {
        return $this->tax !== null;
    }

    /** Taxable + exempt + export value: the template's "total sales". */
    public function totalSales(): ?int
    {
        return $this->converted() ? (int) $this->taxable + (int) $this->exempt + (int) $this->exportValue : null;
    }

    public function money(?int $amount): ?Money
    {
        return $amount === null ? null : Money::of($amount, $this->registerCurrency);
    }

    /** "Web hosting + 2 more", the first line and how many follow. */
    public function goods(): string
    {
        $first = $this->lines[0]['description'] ?? '';

        return count($this->lines) > 1 ? $first.' + '.(count($this->lines) - 1).' more' : $first;
    }

    public function quantity(): ?int
    {
        return $this->lines === [] ? null : array_sum(array_column($this->lines, 'quantity'));
    }

    /** Currency, amount and rate, for a document not in the register currency. */
    public function foreign(): ?string
    {
        return $this->currency === $this->registerCurrency ? null : trim($this->currency.' '.$this->original->toDecimal().($this->rate !== null ? ' at '.$this->rate : ''));
    }
}
