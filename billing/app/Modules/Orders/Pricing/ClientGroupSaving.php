<?php

namespace App\Modules\Orders\Pricing;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Orders\Data\CartItem;

/**
 * What a client's group saves on a cart, for display: the cart re-priced at guest prices minus the client's
 * subtotal. Pricing is untouched; this only explains why a guest's total drops after sign-in.
 */
class ClientGroupSaving
{
    public function __construct(private CartPricer $pricer) {}

    /**
     * @param  list<CartItem>  $items
     * @return array{label: string, amount: int}|null
     */
    public function for(array $items, PricedCart $priced, ?Client $client): ?array
    {
        if ($client === null || $client->client_group_id === null || $items === []) {
            return null;
        }

        $group = ClientGroup::query()->find($client->client_group_id, ['id', 'name', 'discount_percent']);

        if ($group === null) {
            return null;
        }

        $amount = $this->pricer->price($items, $priced->currency)->subtotal() - $priced->subtotal();

        if ($amount <= 0) {
            return null;
        }

        $percent = (float) $group->discount_percent;

        return [
            'label' => $percent > 0
                // "Client discount" is the term the item breakdown uses for the same saving.
                ? __('Client discount (:group, :percent%)', ['group' => $group->name, 'percent' => rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.')])
                : __('Client prices (:group)', ['group' => $group->name]),
            'amount' => $amount,
        ];
    }
}
