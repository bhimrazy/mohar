<?php

namespace App\Modules\Domains\Actions;

use App\Enums\FieldType;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Models\RegistrarAccount;

/**
 * Configure a registrar driver account. Blank secret fields keep the stored secret; numbers are stored as integers.
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

        foreach ($this->registrars->fields($key) as $field) {
            $value = $settings[$field->key] ?? null;

            if ($field->isSecret() && ($value === null || $value === '')) {
                $value = $stored[$field->key] ?? null;
            }

            $clean[$field->key] = match (true) {
                $field->type === FieldType::Boolean => (bool) $value,
                $field->type === FieldType::Number => $value === null || $value === '' ? null : (int) $value,
                default => $value,
            };
        }

        $account->fill(['is_active' => $isActive, 'test_mode' => $testMode, 'settings' => $clean])->save();
        $this->activity->log("Registrar {$key} configured", $account);

        return $account;
    }
}
