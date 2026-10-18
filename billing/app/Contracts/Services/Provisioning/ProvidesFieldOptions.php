<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\ServerConnection;

/**
 * Capability: the options of a product config field whose SettingField::$optionsSource is `remote`, read from a
 * server (the packages a panel offers, a hypervisor's OS templates, a licence system's tiers). Read-only; the host
 * caches the answer for a few minutes.
 */
interface ProvidesFieldOptions
{
    /**
     * @return array<string, string> value => label; the value is stored exactly as returned, the label may carry hints
     *                               such as quotas
     */
    public function fieldOptions(ServerConnection $server, string $fieldKey): array;
}
