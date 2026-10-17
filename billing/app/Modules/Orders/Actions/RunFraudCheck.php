<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Orders\Enums\FraudDecision;
use App\Modules\Orders\Fraud\FraudScreening;
use App\Modules\Orders\Models\Order;

/**
 * Staff re-run of the fraud screen. The result is recorded; the order status is left for staff to change.
 */
class RunFraudCheck
{
    public function __construct(
        private FraudScreening $screening,
        private ActivityLogger $activity,
    ) {}

    public function handle(Order $order): FraudDecision
    {
        $decision = $this->screening->screen($order);
        $this->activity->log("Fraud check re-run for order {$order->number}: {$decision->value}", $order, $order->client_id);

        return $decision;
    }
}
