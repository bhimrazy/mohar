<?php

namespace App\Support\Settings;

final readonly class SettingDefinition
{
    /**
     * @param  string  $key  setting key, e.g. "billing.invoice_prefix" (default value comes from config(key))
     * @param  'string'|'text'|'integer'|'boolean'|'select'|'email'  $type
     * @param  array<string, string>  $options  value => label, for "select"
     * @param  list<string>  $rules  extra Laravel validation rules
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
