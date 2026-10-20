<?php

namespace Mohar\Simulators\Contracts;

use Closure;
use Mohar\Simulators\Kernel\Scenario;

/**
 * Provider-specific control-plane actions (`POST /__sim/actions/{name}`), e.g. raise a dispute or pay a checkout.
 */
interface HasControlActions
{
    /**
     * @return array<string, Closure(array<string, mixed>, Scenario): array<string, mixed>>
     */
    public function actions(): array;
}
