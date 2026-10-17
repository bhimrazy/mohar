<?php

namespace App\Modules\Domains\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Models\RegistrarAccount;

/**
 * Configure a registrar driver account. Blank password fields keep the stored secret.
 */
class SaveRegistrarAccount
{
    public function __construct(private RegistrarManager $registrars, private ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function handle(string $key, bool $isActive, bool $testMode, array $settings): RegistrarAccount
    {
        $account = RegistrarAccount::query()->firstOrNew(['key' => $key]);
        $stored = $account->settings ?? [];
        $clean = [];

        foreach ($this->registrars->driver($key)->settingsSchema() as $field) {
            $value = $settings[$field['key']] ?? null;

            if ($field['type'] === 'password' && ($value === null || $value === '')) {
                $value = $stored[$field['key']] ?? null;
            }

            $clean[$field['key']] = $field['type'] === 'boolean' ? (bool) $value : $value;
        }

        $account->fill(['is_active' => $isActive, 'test_mode' => $testMode, 'settings' => $clean])->save();
        $this->activity->log("Registrar {$key} configured", $account);

        return $account;
    }
}
