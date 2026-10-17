<?php

namespace App\Modules\Orders\Listeners;

use App\Models\User;
use App\Modules\Orders\Services\CartManager;
use App\Modules\Orders\Services\CustomerResolver;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Guest cart → the signed-in client's cart (orders.md §2: guest items win on conflict).
 */
class MergeCartOnLogin
{
    public function __construct(
        private CartManager $carts,
        private CustomerResolver $customers,
        private Request $request,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || ! $this->request->hasSession()) {
            return;
        }

        $session = $this->request->session();
        $client = $this->customers->clientFor($event->user, $session);

        if ($client !== null && $this->carts->mergeGuestCart($session, $client)) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('Your cart is now priced in :currency, your account currency.', ['currency' => $client->currency]),
            ]);
        }
    }
}
