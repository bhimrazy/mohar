<?php

namespace App\Modules\Orders\Services;

use App\Contracts\Catalog\PriceBook;

/**
 * Validates the custom field values a customer enters for a product (catalog.md §2: required, type,
 * dropdown options, validation regex; admin-only fields are never taken from customers). Used when an
 * item is added to the cart and again when the cart is re-quoted at checkout.
 *
 * Regexes come from staff-edited catalog data, so they are evaluated defensively: an invalid pattern
 * rejects the field with a clear message, and a lowered PCRE backtrack limit stops catastrophic
 * patterns quickly (treated as "could not be checked").
 */
class CustomFieldRules
{
    private const MAX_LENGTH = ['textarea' => 5000, 'default' => 1000];

    private const BACKTRACK_LIMIT = 100_000;

    public function __construct(private PriceBook $priceBook) {}

    /**
     * @param  array<int|string, mixed>  $input  field id => value
     * @return array{values: array<int, string>, errors: array<int, string>} accepted values and errors by field id
     */
    public function check(int $productId, array $input): array
    {
        $values = [];
        $errors = [];

        foreach ($this->priceBook->customFieldsFor($productId) as $field) {
            if ((bool) $this->extra($field, 'admin_only')) {
                continue;
            }

            $id = (int) $field['id'];
            $name = (string) $field['name'];
            $value = $this->normalize((string) $field['type'], $input[$id] ?? null);

            if ($value === '') {
                if ($field['required']) {
                    $errors[$id] = $field['type'] === 'checkbox'
                        ? __('Tick :field to continue.', ['field' => $name])
                        : __('Enter :field.', ['field' => $name]);
                }

                continue;
            }

            if (($error = $this->valueError($field, $value)) !== null) {
                $errors[$id] = $error;

                continue;
            }

            $values[$id] = $value;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    private function normalize(string $type, mixed $value): string
    {
        if ($type === 'checkbox') {
            return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true) ? '1' : '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function valueError(array $field, string $value): ?string
    {
        $name = (string) $field['name'];
        $type = (string) $field['type'];
        $max = self::MAX_LENGTH[$type] ?? self::MAX_LENGTH['default'];

        if (mb_strlen($value) > $max) {
            return __(':field must be :max characters or fewer.', ['field' => $name, 'max' => $max]);
        }

        if ($type === 'dropdown' && ! in_array($value, (array) ($field['options'] ?? []), true)) {
            return __('Choose one of the listed options for :field.', ['field' => $name]);
        }

        if ($type === 'link' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            return __('Enter a full web address for :field, starting with https://.', ['field' => $name]);
        }

        $regex = $this->extra($field, 'regex');

        if (! is_string($regex) || $regex === '') {
            return null;
        }

        $matches = $this->matches($regex, $value);

        if ($matches === null) {
            return __(':field cannot be checked right now. Contact us to complete this order.', ['field' => $name]);
        }

        if ($matches === false) {
            $hint = $this->extra($field, 'description');

            return __(':field is not in the expected format.', ['field' => $name]).(is_string($hint) && $hint !== '' ? ' '.$hint : '');
        }

        return null;
    }

    /**
     * Optional keys PriceBook::customFieldsFor() may add (regex, admin_only, description).
     *
     * @param  array<string, mixed>  $field
     */
    private function extra(array $field, string $key): mixed
    {
        return $field[$key] ?? null;
    }

    /**
     * true/false for a match, null when the pattern is invalid or too expensive to evaluate.
     */
    private function matches(string $regex, string $value): ?bool
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);

        try {
            $result = @preg_match($regex, $value);

            return $result === false || preg_last_error() !== PREG_NO_ERROR ? null : $result === 1;
        } finally {
            ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);
        }
    }
}
