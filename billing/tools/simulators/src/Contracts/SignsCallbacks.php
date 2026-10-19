<?php

namespace Mohar\Simulators\Contracts;

use Mohar\Simulators\Kernel\Scenario;

/**
 * A simulator whose server-to-app deliveries (webhooks, inbound mail) are signed the way the provider signs them.
 */
interface SignsCallbacks
{
    /**
     * Signature headers for a delivery body. $wrongSecret signs with a secret the app does not know.
     *
     * @return array<string, string>
     */
    public function sign(Scenario $scenario, string $body, int $timestamp, bool $wrongSecret = false): array;
}
