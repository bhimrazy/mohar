<?php

namespace App\Contracts\Billing;

/**
 * Provider contract, opt-in (ADR 0010, ADR 0012): a gateway driver whose settings have a format the provider dictates
 * (connectIPS: digits-only merchant ID, length limits). Billing calls it when staff save the gateway form and shows
 * each message at its field, so a bad value is refused on save instead of at checkout.
 */
interface ValidatesGatewaySettings
{
    /**
     * @param  array<string, string>  $settings  the submitted non-secret values, trimmed; empty ones left out
     * @return array<string, string> setting key => plain-text message, for each value the provider would refuse
     */
    public function settingsErrors(array $settings): array;
}
