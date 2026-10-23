<?php

namespace App\Data\Reports;

use App\Enums\FilterType;
use Illuminate\Validation\Rule;

/**
 * One input a report accepts. The pipeline validates the value by its type (a date parses as Y-m-d, a select is one
 * of its options, a text is a string of at most 255 characters, no value is an array) and then applies `rules`.
 * Filters named `from` and `to` (both Y-m-d) become the report period, and `to` may not be before `from`.
 *
 * `loggable` says whether the value may be written to the activity log with the export. It is true for dates,
 * selects and booleans, and false for text, which may hold a name or an email address.
 *
 * @api
 */
final readonly class ReportFilter
{
    public bool $loggable;

    /**
     * @param  array<string, string>  $options  value => label, for a select
     * @param  list<mixed>  $rules  extra Laravel validation rules, applied after the type's own
     */
    public function __construct(
        public string $key,
        public string $label,
        public FilterType $type = FilterType::Text,
        public array $options = [],
        public array $rules = ['nullable'],
        public mixed $default = null,
        ?bool $loggable = null,
    ) {
        $this->loggable = $loggable ?? $type !== FilterType::Text;
    }

    /**
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        $own = match ($this->type) {
            FilterType::Date => ['string', 'date_format:Y-m-d'],
            FilterType::Select => ['string', Rule::in(array_map('strval', array_keys($this->options)))],
            FilterType::Boolean => ['boolean'],
            FilterType::Text => ['string', 'max:255'],
        };

        return [...$this->rules, ...$own];
    }
}
