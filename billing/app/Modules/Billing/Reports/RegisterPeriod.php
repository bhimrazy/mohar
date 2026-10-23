<?php

namespace App\Modules\Billing\Reports;

use App\Contracts\Core\CompanyClock;
use App\Data\Reports\ReportFilter;
use App\Data\Reports\ReportQuery;
use App\Enums\FilterType;
use App\Modules\Billing\Support\BikramSambat;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use OutOfRangeException;

/**
 * The period of a VAT register (vat-register §6.3): a BS month (the VAT tax period), a BS fiscal year (1 Shrawan to
 * the end of Asar) or any AD date range. A BS period selects documents by their stored `bs_period`, so a later
 * correction to the BS table moves no filed document (ADR 0024 §2); an AD range selects by `issue_date`. With no
 * filter it is the last closed BS month on the company calendar.
 */
final readonly class RegisterPeriod
{
    /**
     * @param  'month'|'year'|'range'  $kind
     * @param  string  $from  first day, Y-m-d (AD)
     * @param  string  $to  last day, Y-m-d (AD)
     * @param  string|null  $firstMonth  first BS period key ("2083-04"); null for an AD range
     * @param  string|null  $lastMonth  last BS period key
     */
    private function __construct(
        public string $kind,
        public string $from,
        public string $to,
        public ?string $firstMonth,
        public ?string $lastMonth,
    ) {}

    /** @return list<ReportFilter> */
    public static function filters(): array
    {
        return [
            new ReportFilter('month', 'BS month', FilterType::Text, rules: ['nullable', 'prohibits:year,from,to', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', self::inTable(fn (string $v): array => [(int) substr($v, 0, 4)])], loggable: true),
            new ReportFilter('year', 'BS fiscal year', FilterType::Text, rules: ['nullable', 'prohibits:from,to', 'regex:/^\d{4}\.\d{3}$/', self::inTable(fn (string $v): array => [(int) substr($v, 0, 4), (int) substr($v, 0, 4) + 1])], loggable: true),
            new ReportFilter('from', 'From (AD)', FilterType::Date, rules: ['nullable', 'required_with:to']),
            new ReportFilter('to', 'To (AD)', FilterType::Date, rules: ['nullable', 'required_with:from']),
        ];
    }

    public static function of(ReportQuery $query, CompanyClock $clock): self
    {
        if ($query->from !== null && $query->to !== null) {
            return new self('range', $query->from, $query->to, null, null);
        }

        if (is_string($year = $query->filter('year')) && $year !== '') {
            $start = (int) substr($year, 0, 4);

            return self::months('year', $start, 4, $start + 1, 3);
        }

        if (is_string($month = $query->filter('month')) && $month !== '') {
            return self::months('month', (int) substr($month, 0, 4), (int) substr($month, 5, 2));
        }

        return self::lastClosedMonth($clock);
    }

    /** The BS month before the company's current one: the month a register is usually closed for. */
    public static function lastClosedMonth(CompanyClock $clock): self
    {
        $today = BikramSambat::fromAd($clock->todayDate());

        return $today->month === 1 ? self::months('month', $today->year - 1, 12) : self::months('month', $today->year, $today->month - 1);
    }

    /**
     * Limit a query to the period: by the stored BS period key, or by the AD issue date for a range. The date bound is
     * half-open, because SQLite stores a date cast as "Y-m-d 00:00:00".
     */
    public function scope(Builder $query, string $table): Builder
    {
        return $this->firstMonth !== null
            ? $query->whereBetween("{$table}.bs_period", [$this->firstMonth, $this->lastMonth])
            : $query->where("{$table}.issue_date", '>=', $this->from)
                ->where("{$table}.issue_date", '<', CarbonImmutable::parse($this->to, 'UTC')->addDay()->format('Y-m-d'));
    }

    /**
     * The BS months of the period, in order; empty for an AD range.
     *
     * @return array<string, string> period key => "Shrawan 2083"
     */
    public function monthKeys(): array
    {
        if ($this->firstMonth === null || $this->lastMonth === null) {
            return [];
        }

        [$year, $month] = array_map('intval', explode('-', $this->firstMonth));
        $keys = [];

        while (($key = sprintf('%d-%02d', $year, $month)) <= $this->lastMonth) {
            $keys[$key] = BikramSambat::MONTHS[$month - 1].' '.$year;
            [$year, $month] = $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
        }

        return $keys;
    }

    /** "Kartik 2083 (month 7)", "Fiscal year 2083/84" or "2026-10-01 to 2026-10-31". */
    public function label(): string
    {
        return match ($this->kind) {
            'month' => array_values($this->monthKeys())[0].' (month '.(int) substr((string) $this->firstMonth, 5, 2).')',
            'year' => sprintf('Fiscal year %d/%02d', $start = (int) substr((string) $this->firstMonth, 0, 4), ($start + 1) % 100),
            default => "{$this->from} to {$this->to}",
        };
    }

    /** The BS fiscal year(s) the period falls in, "2083.084", or two joined by " – " for an AD range across years. */
    public function fiscalYear(): string
    {
        $years = [];

        foreach ([$this->from, $this->to] as $date) {
            try {
                $years[] = BikramSambat::fiscalYear(BikramSambat::fromAd(new DateTimeImmutable($date)));
            } catch (OutOfRangeException) {
                $years[] = '—';
            }
        }

        return implode(' – ', array_unique($years));
    }

    /**
     * @param  'month'|'year'  $kind
     */
    private static function months(string $kind, int $year, int $month, ?int $lastYear = null, ?int $lastMonth = null): self
    {
        $lastYear ??= $year;
        $lastMonth ??= $month;

        return new self(
            $kind,
            BikramSambat::toAd($year, $month, 1)->format('Y-m-d'),
            BikramSambat::toAd($lastYear, $lastMonth, BikramSambat::daysInMonth($lastYear, $lastMonth))->format('Y-m-d'),
            sprintf('%d-%02d', $year, $month),
            sprintf('%d-%02d', $lastYear, $lastMonth),
        );
    }

    /**
     * A validation rule: the BS years the value names are in the calendar table, and a fiscal year's second part
     * follows its first ("2083.084").
     *
     * @param  Closure(string): list<int>  $years
     */
    private static function inTable(Closure $years): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($years): void {
            if (! is_string($value) || preg_match('/^\d{4}([-.]\d{2,3})$/', $value) !== 1) {
                return;
            }

            $named = $years($value);
            $outside = array_filter($named, fn (int $year): bool => $year < BikramSambat::FIRST_YEAR || $year > BikramSambat::LAST_YEAR);

            if ($outside !== [] || (str_contains($value, '.') && (int) substr($value, 5) !== ($named[0] + 1) % 1000)) {
                $fail(__('Choose a period between :first and :last BS.', ['first' => BikramSambat::FIRST_YEAR, 'last' => BikramSambat::LAST_YEAR]));
            }
        };
    }
}
