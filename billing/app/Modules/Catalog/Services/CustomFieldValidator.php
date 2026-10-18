<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\CustomFieldType;
use App\Modules\Catalog\Models\ProductCustomField;
use App\Modules\Catalog\Support\RegexMatcher;

/**
 * Validates client-submitted custom field values for a product (order form).
 *
 * Staged: Orders validates against PriceBook::customFieldsFor() (regex/required/options) today; this
 * class becomes reachable when a validation method is added to the contract.
 */
class CustomFieldValidator
{
    /**
     * @param  array<int|string, mixed>  $values  field id => submitted value
     * @return array<int, string> field id => error message
     */
    public function validate(int $productId, array $values, bool $asStaff = false): array
    {
        $fields = ProductCustomField::query()
            ->where('product_id', $productId)
            ->when(! $asStaff, fn ($query) => $query->where('is_admin_only', false)->where('show_on_order', true))
            ->orderBy('sort_order')->orderBy('id')->get();

        $errors = [];

        foreach ($fields as $field) {
            $raw = $values[$field->id] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';

            if ($error = $this->error($field, $value)) {
                $errors[$field->id] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function message(string $key, array $replace): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    private function error(ProductCustomField $field, string $value): ?string
    {
        if ($field->type === CustomFieldType::Checkbox) {
            return $field->is_required && ! in_array(strtolower($value), ['1', 'on', 'yes', 'true'], true)
                ? $this->message(':field must be checked.', ['field' => $field->name])
                : null;
        }

        if ($value === '') {
            return $field->is_required ? $this->message('Enter :field.', ['field' => $field->name]) : null;
        }

        if ($field->type === CustomFieldType::Dropdown && ! in_array($value, $field->options ?? [], true)) {
            return $this->message('Choose one of the listed options for :field.', ['field' => $field->name]);
        }

        if ($field->type === CustomFieldType::Link && (filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            return $this->message('Enter a valid URL for :field.', ['field' => $field->name]);
        }

        if ($field->validation_regex !== null && $field->validation_regex !== '' && ! RegexMatcher::matches($field->validation_regex, $value)) {
            return $this->message(':field is not in the expected format.', ['field' => $field->name]);
        }

        return null;
    }
}
