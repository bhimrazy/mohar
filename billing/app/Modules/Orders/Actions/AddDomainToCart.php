<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Pricing\CartPricer;
use Illuminate\Validation\ValidationException;

/**
 * Adds a standalone domain registration or transfer. Ordering the same domain again replaces the
 * earlier cart entry.
 */
class AddDomainToCart
{
    public function __construct(
        private CartPricer $pricer,
        private Settings $settings,
    ) {}

    /**
     * @param  array{domain: string, action: string, years: int, epp_code?: ?string, id_protection?: bool}  $input
     *
     * @throws ValidationException
     */
    public function handle(Cart $cart, array $input, ?Client $client = null): CartItem
    {
        $transfer = $input['action'] === 'transfer';
        $epp = trim((string) ($input['epp_code'] ?? ''));

        if ($transfer && $epp === '' && $this->settings->get('orders.transfer_requires_epp', true)) {
            throw ValidationException::withMessages(['epp_code' => __('Enter the authorization (EPP) code from your current registrar.')]);
        }

        $item = new CartItem(
            key: CartItem::newKey(),
            kind: $transfer ? CartItemKind::DomainTransfer : CartItemKind::DomainRegister,
            domainName: strtolower(trim($input['domain'])),
            eppCode: $transfer && $epp !== '' ? $epp : null,
            years: (int) $input['years'],
            idProtection: (bool) ($input['id_protection'] ?? false),
        );

        $priced = $this->pricer->price([$item], $cart->currency, null, $client)->items[0];

        if (! $priced->isValid()) {
            throw ValidationException::withMessages(['domain' => $priced->errors[0]]);
        }

        $others = array_values(array_filter($cart->cartItems(), fn (CartItem $existing): bool => $existing->kind === CartItemKind::Product || $existing->domain() !== $item->domain()));
        $cart->setCartItems([...$others, $item]);

        return $item;
    }
}
