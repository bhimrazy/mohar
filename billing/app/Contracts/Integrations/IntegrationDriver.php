<?php

namespace App\Contracts\Integrations;

/**
 * Identity every driver family shares (provisioning, registrar). Provider contract (ADR 0010): implemented by
 * drivers, possibly from outside packages; grow it only through capability interfaces.
 */
interface IntegrationDriver
{
    /**
     * The key the driver is registered under: `[a-z0-9_]{1,50}`, stable forever (it is stored on products,
     * servers and domains).
     */
    public function key(): string;

    /**
     * Name shown to staff, e.g. "cPanel / WHM".
     */
    public function name(): string;
}
