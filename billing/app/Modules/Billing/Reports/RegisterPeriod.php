<?php

namespace App\Modules\Billing\Reports;

use App\Contracts\Core\CompanyClock;
use App\Data\Reports\ReportFilter;
use App\Data\Reports\ReportQuery;
use App\Enums\FilterType;
use App\Modules\Billing\Support\BikramSambat;
use App\Modules\Core\Support\DateFormats;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
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

    /** The longest AD range, so a register screen's totals stay bounded: a BS year has at most 366 days. */
    public const MAX_RANGE_DAYS = 366;

    /**
     * The period filters. Every value is written to the `report.exported` entry; the screen passes the default month
     * explicitly, so an export always records the period it covers.
     *
     * @return list<ReportFilter>
     */
    public static function filters(): array
    {
        return [
            new ReportFilter('month', 'BS month', FilterType::Text, rules: ['nullable', 'prohibits:year,from,to', self::inTable('/^(\d{4})-(0[1-9]|1[0-2])$/', __('Choose a BS month from the list.'))], loggable: true),
            new ReportFilter('year', 'BS fiscal year', FilterType::Text, rules: ['nullable', 'prohibits:from,to', self::inTable('/^(\d{4})\.(\d{3})$/', __('Choose a BS fiscal year from the list.'))], loggable: true),
            new ReportFilter('from', 'From (AD)', FilterType::Date, rules: ['nullable', 'required_with:to'], loggable: true),
            new ReportFilter('to', 'To (AD)', FilterType::Date, rules: ['nullable', 'required_with:from', self::withinYear()], loggable: true),
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

    /** "Kartik 2083 (month 7)", "Fiscal year 2083/84" or "1 Oct 2026 – 31 Oct 2026" in the app's date format. */
    public function label(): string
    {
        $format = DateFormats::current();

        return match ($this->kind) {
            'month' => array_values($this->monthKeys())[0].' (month '.(int) substr((string) $this->firstMonth, 5, 2).')',
            'year' => 'Fiscal year '.$this->fiscalYear(),
            default => CarbonImmutable::parse($this->from, 'UTC')->format($format).' – '.CarbonImmutable::parse($this->to, 'UTC')->format($format),
        };
    }

    /** The BS fiscal year(s) the period falls in, "2083/84", or two joined by " – " for an AD range across years. */
    public function fiscalYear(): string
    {
        $years = [];

        foreach ([$this->from, $this->to] as $date) {
            try {
                $start = (int) substr(BikramSambat::fiscalYear(BikramSambat::fromAd(new DateTimeImmutable($date))), 0, 4);
                $years[] = sprintf('%d/%02d', $start, ($start + 1) % 100);
            } catch (OutOfRangeException) {
                $years[] = '—';
            }
        }

        return implode(' – ', array_unique($years));
    }

    /** For a file name: "2083-06-asoj", "fy-2083-84" or "2026-10-01-to-2026-10-31". */
    public function slug(): string
    {
        return match ($this->kind) {
            'month' => $this->firstMonth.'-'.strtolower(BikramSambat::MONTHS[(int) substr((string) $this->firstMonth, 5, 2) - 1]),
            'year' => 'fy-'.str_replace('/', '-', $this->fiscalYear()),
            default => "{$this->from}-to-{$this->to}",
        };
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
     * A validation rule: the value has the pattern (BS year in group 1), its years are in the calendar table, and a
     * fiscal year's second part follows its first ("2083.084"). Any failure gives the one plain message.
     */
    private static function inTable(string $pattern, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pattern, $message): void {
            if (! is_string($value) || preg_match($pattern, $value, $match) !== 1) {
                $fail($message);

                return;
            }

            $year = (int) $match[1];
            $last = str_contains($value, '.') ? $year + 1 : $year;

            if ($year < BikramSambat::FIRST_YEAR || $last > BikramSambat::LAST_YEAR || (str_contains($value, '.') && (int) $match[2] !== $last % 1000)) {
                $fail($message);
            }
        };
    }

    /** A validation rule on `to`: the range is at most MAX_RANGE_DAYS long. */
    private static function withinYear(): ValidationRule&DataAwareRule
    {
        return new class implements DataAwareRule, ValidationRule
        {
            /** @var array<string, mixed> */
            private array $data = [];

            public function setData(array $data): static
            {
                $this->data = $data;

                return $this;
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $from = $this->data['from'] ?? null;

                // A malformed date is reported by the filter's own date rule.
                if (! is_string($from) || ! is_string($value)) {
                    return;
                }

                if (rescue(fn (): bool => CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($value, 'UTC')) >= RegisterPeriod::MAX_RANGE_DAYS, false, report: false)) {
                    $fail(__('Choose a date range of one year or less, or pick a BS fiscal year.'));
                }
            }
        };
    }
}
