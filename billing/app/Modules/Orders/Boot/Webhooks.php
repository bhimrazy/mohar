<?php

namespace App\Modules\Orders\Boot;

use App\Events\Affiliates\AffiliateCommissionEarned;
use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderMarkedFraud;
use App\Events\Orders\OrderPlaced;
use App\Support\Events\WebhookEvents;
use Illuminate\Contracts\Container\Container;

final class Webhooks
{
    public function __invoke(Container $app): void
    {
        $webhooks = $app->make(WebhookEvents::class);
        $webhooks->register(OrderPlaced::class, 'order.placed', 'order', 'orderId');
        $webhooks->register(OrderMarkedFraud::class, 'order.fraud', 'order', 'orderId');
        $webhooks->register(OrderCancelled::class, 'order.cancelled', 'order', 'orderId');
        $webhooks->register(AffiliateCommissionEarned::class, 'affiliate.commission_earned', 'affiliate', 'affiliateId');
    }
}
