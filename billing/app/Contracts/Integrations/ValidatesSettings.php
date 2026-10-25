<?php

namespace App\Contracts\Integrations;

/**
 * Provider contract, opt-in (ADR 0010, ADR 0012): an integration driver (gateway, registrar, provisioning) whose
 * settings have formats or rules between fields that a setting field list cannot express (connectIPS: a digits-only
 * merchant ID, length limits, no commas). The host calls it when staff save the settings, after its own field checks,
 * and shows each message at its field. Only the gateway form calls it so far; other hosts adopt it as they need it.
 *
 * It runs inside the save request, so it is pure: no network call, no database access, no settings lookups, nothing
 * that can fail or wait. Secret values are never passed.
 */
interface ValidatesSettings
{
    /**
     * @param  array<string, string>  $settings  the submitted non-secret values, trimmed; empty ones left out
     * @return array<string, string> setting key => plain message for the person who typed it; empty when acceptable.
     *                               The message names the field by its label and never repeats the value.
     */
    public function validateSettings(array $settings): array;
}
