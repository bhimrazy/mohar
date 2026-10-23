<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Catalog\PriceBook;
use App\Enums\BillingCycle;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Pricing\CartPricer;
use App\Modules\Orders\Services\CustomFieldRules;
use App\Modules\Orders\Services\DomainGuard;
use App\Modules\Orders\Services\TldAttributeInput;
use Illuminate\Validation\ValidationException;

/**
 * Validates a configured product against the catalog (cycle, options, custom fields, addons, domain)
 * and adds it to the cart, or replaces the item being edited.
 */
class AddProductToCart
{
    public function __construct(
        private PriceBook $priceBook,
        private CartPricer $pricer,
        private CustomFieldRules $customFieldRules,
        private Settings $settings,
        private TldAttributeInput $tldAttributes,
    ) {}

    /**
     * @param  array{product_id: int, cycle: string, config_options?: array<int|string, mixed>, custom_fields?: array<int|string, mixed>, domain_mode?: ?string, domain_name?: ?string, epp_code?: ?string, addon_ids?: list<int|string>, tld_attributes?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function handle(Cart $cart, array $input, ?Client $client = null, ?string $replaceKey = null): CartItem
    {
        $productId = (int) $input['product_id'];
        $product = $this->priceBook->product($productId);

        if ($product === null || ! $product->active) {
            throw ValidationException::withMessages(['product_id' => __('This product is no longer available.')]);
        }

        $cycle = BillingCycle::tryFrom($input['cycle']);

        if ($cycle === null || ! in_array($cycle, $this->priceBook->availableCycles($productId, $cart->currency), true)) {
            throw ValidationException::withMessages(['cycle' => __('Choose one of the available billing cycles.')]);
        }

        $errors = [];
        $configOptions = $this->configOptions($productId, $cart->currency, $input['config_options'] ?? [], $errors);
        $customFields = $this->customFields($productId, $input['custom_fields'] ?? [], $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $item = new CartItem(
            key: $replaceKey ?? CartItem::newKey(),
            kind: CartItemKind::Product,
            productId: $productId,
            cycle: $cycle,
            configOptions: $configOptions,
            customFields: $customFields,
            domainMode: $this->domainMode($input, $product->requiresDomain),
            domainName: ($name = trim((string) ($input['domain_name'] ?? ''))) === '' ? null : strtolower($name),
            eppCode: ($input['domain_mode'] ?? null) === 'transfer' ? $this->eppCode($input) : null,
            addonIds: $this->addonIds($productId, $cart->currency, $input['addon_ids'] ?? []),
            tldAttributes: ($input['domain_mode'] ?? null) === 'register' ? $this->tldAttributes->clean($input['domain_name'] ?? null, $input['tld_attributes'] ?? null, 'domain_name') : [],
        );

        $priced = $this->pricer->price([$item], $cart->currency, null, $client)->items[0];

        if (! $priced->isValid()) {
            throw ValidationException::withMessages([$item->domain() !== null && str_contains($priced->errors[0], $item->domain()) ? 'domain_name' : 'product_id' => $priced->errors[0]]);
        }

        $items = $cart->cartItems();
        $replaced = false;

        foreach ($items as $index => $existing) {
            if ($existing->key === $item->key) {
                $items[$index] = $item;
                $replaced = true;
            }
        }

        $cart->setCartItems($replaced ? $items : [...$items, $item]);

        return $item;
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @param  array<string, mixed>  $errors  collects field errors for ValidationException::withMessages()
     * @return array<int, int>
     */
    private function configOptions(int $productId, string $currency, array $input, array &$errors): array
    {
        $selected = [];

        foreach ($this->priceBook->configOptionsFor($productId, $currency) as $option) {
            $value = $input[$option['id']] ?? null;

            if ($option['type'] === 'quantity') {
                if ($value !== null && (! is_numeric($value) || (int) $value < 0)) {
                    $errors["config_options.{$option['id']}"] = __('Enter a quantity of 0 or more for :option.', ['option' => $option['name']]);
                }

                $selected[$option['id']] = max(0, (int) $value);

                continue;
            }

            $valueIds = array_column($option['values'], 'id');

            if ($value === null || $value === '') {
                if ($option['type'] === 'yes_no') {
                    continue;
                }

                $errors["config_options.{$option['id']}"] = __('Choose an option for :option.', ['option' => $option['name']]);

                continue;
            }

            if (! in_array((int) $value, $valueIds, true)) {
                $errors["config_options.{$option['id']}"] = __('Choose one of the listed options for :option.', ['option' => $option['name']]);

                continue;
            }

            $selected[$option['id']] = (int) $value;
        }

        return $selected;
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @param  array<string, mixed>  $errors  collects field errors for ValidationException::withMessages()
     * @return array<int, string>
     */
    private function customFields(int $productId, array $input, array &$errors): array
    {
        $checked = $this->customFieldRules->check($productId, $input);

        foreach ($checked['errors'] as $fieldId => $message) {
            $errors["custom_fields.{$fieldId}"] = $message;
        }

        return $checked['values'];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function domainMode(array $input, bool $requiresDomain): ?string
    {
        $mode = $input['domain_mode'] ?? null;
        $name = trim((string) ($input['domain_name'] ?? ''));

        if ($mode === null || $mode === '') {
            if ($requiresDomain) {
                throw ValidationException::withMessages(['domain_name' => __('Choose a domain for this product.')]);
            }

            return null;
        }

        if (! in_array($mode, ['register', 'transfer', 'existing'], true)) {
            throw ValidationException::withMessages(['domain_mode' => __('Choose how you want to use a domain.')]);
        }

        if (! DomainGuard::isValidName($name)) {
            throw ValidationException::withMessages(['domain_name' => __('Enter a valid domain name, for example example.com.')]);
        }

        if (($error = DomainGuard::registryLevelError($name)) !== null) {
            throw ValidationException::withMessages(['domain_name' => $error]);
        }

        return (string) $mode;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function eppCode(array $input): ?string
    {
        $code = trim((string) ($input['epp_code'] ?? ''));

        if ($code === '' && $this->settings->get('orders.transfer_requires_epp', true)) {
            throw ValidationException::withMessages(['epp_code' => __('Enter the authorization (EPP) code from your current registrar.')]);
        }

        return $code === '' ? null : $code;
    }

    /**
     * @param  list<int|string>  $input
     * @return list<int>
     */
    private function addonIds(int $productId, string $currency, array $input): array
    {
        $available = array_column($this->priceBook->addonsFor($productId, $currency), 'id');
        $ids = array_values(array_unique(array_map(intval(...), $input)));

        foreach ($ids as $id) {
            if (! in_array($id, $available, true)) {
                throw ValidationException::withMessages(['addon_ids' => __('One of the selected addons is not available for this product.')]);
            }
        }

        return $ids;
    }
}
