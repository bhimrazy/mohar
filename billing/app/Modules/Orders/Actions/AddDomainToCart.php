<?php

namespace App\Modules\Orders\Actions;

use App\Contracts\Domains\DomainPricing;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Pricing\CartPricer;
use App\Modules\Orders\Services\TldAttributeInput;
use Illuminate\Validation\ValidationException;

/**
 * Adds a standalone domain registration or transfer. Ordering the same domain again replaces the
 * earlier cart entry.
 */
class AddDomainToCart
{
    public function __construct(
        private CartPricer $pricer,
        private DomainPricing $pricing,
        private Settings $settings,
        private TldAttributeInput $tldAttributes,
    ) {}

    /**
     * @param  array{domain: string, action: string, years: int, epp_code?: ?string, id_protection?: bool, tld_attributes?: mixed}  $input
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

        $tld = $this->pricing->split(strtolower(trim($input['domain'])))[1] ?? null;

        $terms = $tld === null ? [] : $this->pricing->terms($tld, $transfer ? 'transfer' : 'register', $cart->currency, $client?->id);

        // No terms at all: the pricer below explains that the extension is not sold here.
        if ($terms !== [] && ! in_array((int) $input['years'], $terms, true)) {
            throw ValidationException::withMessages(['years' => __('Choose one of the registration periods offered for :tld.', ['tld' => $tld])]);
        }

        $item = new CartItem(
            key: CartItem::newKey(),
            kind: $transfer ? CartItemKind::DomainTransfer : CartItemKind::DomainRegister,
            domainName: strtolower(trim($input['domain'])),
            eppCode: $transfer && $epp !== '' ? $epp : null,
            years: (int) $input['years'],
            idProtection: (bool) ($input['id_protection'] ?? false),
            tldAttributes: $transfer ? [] : $this->tldAttributes->clean($input['domain'], $input['tld_attributes'] ?? null),
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
