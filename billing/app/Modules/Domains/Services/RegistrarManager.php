<?php

namespace App\Modules\Domains\Services;

use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Integrations\SettingField;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Support\Domains\RegistrarDriverRegistry;

/**
 * Registrar accounts (ADR 0009 §5): loads registrar_accounts, builds the RegistrarConfig every driver call receives
 * and answers whether a registrar is enabled. Drivers themselves live in the kernel RegistrarDriverRegistry.
 */
class RegistrarManager
{
    public function __construct(private RegistrarDriverRegistry $drivers) {}

    /**
     * The account the driver registered as $key runs against; an unconfigured account has no settings.
     */
    public function config(string $key, ?RegistrarAccount $account = null): RegistrarConfig
    {
        $account ??= $this->account($key);
        $secretKeys = array_values(array_map(
            fn (SettingField $field): string => $field->key,
            array_filter($this->drivers->fields($key), fn (SettingField $field): bool => $field->isSecret()),
        ));

        return new RegistrarConfig($key, $account->settings ?? [], $account->test_mode ?? false, $secretKeys);
    }

    /** The saved account row, or null; callers that need it for several checks load it once and pass it on. */
    public function account(string $key): ?RegistrarAccount
    {
        return RegistrarAccount::query()->where('key', $key)->first();
    }

    /**
     * Whether the driver is registered and usable: its account is enabled, or it has none and needs none.
     */
    public function isActive(string $key, ?RegistrarAccount $account = null): bool
    {
        if (! $this->drivers->has($key)) {
            return false;
        }

        $account ??= $this->account($key);

        return $account === null ? ! $this->drivers->needsAccount($key) : $account->is_active;
    }
}
