<?php

namespace App\Contracts\Integrations;

/**
 * Identity every driver family shares (provisioning, registrar). Provider contract (ADR 0010): implemented by
 * drivers, possibly from outside packages; grow it only through capability interfaces.
 */
interface IntegrationDriver
{
    /**
     * The key the driver is registered under: a lowercase letter, then up to 49 of `[a-z0-9_]` (DriverRegistry::KEY).
     * Stable forever: it is stored on products, servers and domains.
     */
    public function key(): string;

    /**
     * Name shown to staff, e.g. "cPanel / WHM".
     */
    public function name(): string;
}
