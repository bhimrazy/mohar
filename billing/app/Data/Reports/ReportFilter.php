<?php

namespace App\Data\Reports;

/**
 * One input a report accepts. `rules` are Laravel validation rules for the value; `from` and `to` (company dates,
 * Y-m-d) are the two keys ReportQuery turns into the period.
 *
 * @api
 */
final readonly class ReportFilter
{
    /**
     * @param  'date'|'text'|'select'|'boolean'  $type
     * @param  array<string, string>  $options  value => label, for "select"
     * @param  list<string>  $rules
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public array $options = [],
        public array $rules = ['nullable'],
        public mixed $default = null,
    ) {}
}
