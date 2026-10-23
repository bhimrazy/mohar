<?php

namespace App\Support\Settings;

final readonly class SettingDefinition
{
    /**
     * @param  string  $key  setting key, e.g. "billing.invoice_prefix" (default value comes from config(key))
     * @param  'string'|'text'|'integer'|'boolean'|'select'|'email'|'country'  $type  "country" is chosen by name and stores the ISO 3166-1 alpha-2 code
     * @param  array<string, string>  $options  value => label, for "select"
     * @param  list<mixed>  $rules  extra Laravel validation rules
     * @param  bool  $secret  write-only (API keys, passwords): never sent back to the UI, masked in the activity log
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'string',
        public ?string $help = null,
        public array $options = [],
        public array $rules = [],
        public bool $secret = false,
    ) {}
}
