<?php

namespace App\Support\Billing;

/**
 * Whether test money may exist in this deployment: the simulated sandbox gateway, and provider sandbox modes
 * (Khalti dev.khalti.com, eSewa UAT). Off in production unless explicitly forced with BILLING_SANDBOX_ENABLED=true;
 * on everywhere else unless forced off.
 */
final class SandboxPolicy
{
    public static function allowed(): bool
    {
        $configured = config('billing_sandbox.enabled');

        return $configured === null || $configured === '' ? ! app()->isProduction() : filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }
}
