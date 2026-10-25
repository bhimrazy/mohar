<?php

namespace App\Support\Integrations;

use App\Data\Integrations\SettingField;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a new secret setting value shorter than its field's minimum (SettingField::minimumSecretLength(), bytes once
 * trimmed). An empty value passes: on a settings form it keeps the stored one. The message says "characters" while
 * bytes are counted, as the Redactor matches them: they differ only for non-ASCII values, which pass sooner.
 *
 * @api
 */
final readonly class SecretMinimumLength implements ValidationRule
{
    public function __construct(private SettingField $field) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->field->isTooShort($value)) {
            $fail(__(':label must be at least :min characters. A shorter secret cannot be kept out of logs.', [
                'label' => $this->field->label,
                'min' => $this->field->minimumSecretLength(),
            ]));
        }
    }
}
