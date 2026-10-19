<?php

namespace Tests\Fakes\Concerns;

use App\Data\Catalog\ModuleInputs;
use App\Data\Catalog\ProductSummary;

/**
 * PriceBook::moduleInputs() and productsForDriver() for the in-memory price books. Uses the fake's $products and
 * $customFields; configurable options come from $moduleOptions.
 */
trait FakesModuleInputs
{
    /** @var array<int, array<int, array{key: string, values: array<int, string>}>> product id => option id => module key and value id => module value */
    public array $moduleOptions = [];

    public function moduleInputs(int $productId, array $optionSelections, array $customFieldValues): ModuleInputs
    {
        $options = [];

        foreach ($this->moduleOptions[$productId] ?? [] as $optionId => $option) {
            $selected = $optionSelections[$optionId] ?? null;

            if ($selected !== null && isset($option['values'][(int) $selected])) {
                $options[$option['key']] = $option['values'][(int) $selected];
            }
        }

        $fields = [];
        $secrets = [];

        foreach ($this->customFields[$productId] ?? [] as $field) {
            if (! array_key_exists($field['id'], $customFieldValues)) {
                continue;
            }

            if ($field['type'] === 'password' && is_scalar($customFieldValues[$field['id']])) {
                $secrets[] = (string) $customFieldValues[$field['id']];
            }

            if (($field['module_key'] ?? '') !== '') {
                $fields[$field['module_key']] = $customFieldValues[$field['id']];
            }
        }

        return new ModuleInputs($options, $fields, $secrets);
    }

    public function productsForDriver(string $driverKey): array
    {
        return array_values(array_filter($this->products, fn (ProductSummary $product): bool => $product->provisioningDriver === $driverKey));
    }
}
