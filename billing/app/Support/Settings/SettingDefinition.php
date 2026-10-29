<?php

namespace App\Support\Settings;

use Closure;

/**
 * One editable setting in a SettingsRegistry section.
 *
 * @api
 */
final readonly class SettingDefinition
{
    /**
     * @param  string  $key  setting key, e.g. "billing.invoice_prefix" (default value comes from config(key))
     * @param  'string'|'text'|'integer'|'boolean'|'select'|'multiselect'|'email'|'country'|'money'  $type  "country" is chosen by name and stores the ISO 3166-1 alpha-2 code; "multiselect" stores a list of option values; "money" is entered in currency units and stores integer minor units
     * @param  array<int|string, string>|Closure(): array<int|string, string>  $options  value => label, for "select" and "multiselect"; a closure is read when the form is built or a save is validated (options from the database)
     * @param  list<mixed>  $rules  extra Laravel validation rules
     * @param  bool  $secret  write-only (API keys, passwords): never sent back to the UI, masked in the activity log
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'string',
        public ?string $help = null,
        public array|Closure $options = [],
        public array $rules = [],
        public bool $secret = false,
    ) {}

    /**
     * The choices for a select or multiselect, value => label.
     *
     * @return array<int|string, string>
     */
    public function options(): array
    {
        return $this->options instanceof Closure ? ($this->options)() : $this->options;
    }
}
