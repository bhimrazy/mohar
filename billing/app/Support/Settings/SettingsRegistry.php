<?php

namespace App\Support\Settings;

/**
 * Describes editable settings so the Platform module can render one generic settings UI.
 * Modules register sections in bootModule():
 *
 *   app(SettingsRegistry::class)->section('billing', 'Billing', [
 *       new SettingDefinition('billing.invoice_prefix', 'Invoice number prefix'),
 *       new SettingDefinition('billing.late_fee_days', 'Days after due date to add a late fee', 'integer', rules: ['min:0']),
 *   ], permission: 'billing.settings.manage');
 */
class SettingsRegistry
{
    /** @var array<string, array{title: string, permission: ?string, settings: list<SettingDefinition>}> */
    private array $sections = [];

    /**
     * @param  list<SettingDefinition>  $settings
     */
    public function section(string $key, string $title, array $settings, ?string $permission = null): void
    {
        $this->sections[$key] = ['title' => $title, 'permission' => $permission, 'settings' => $settings];
    }

    /**
     * @return array<string, array{title: string, permission: ?string, settings: list<SettingDefinition>}>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * The registered definition for a setting key, if any.
     */
    public function definition(string $key): ?SettingDefinition
    {
        foreach ($this->sections as $section) {
            foreach ($section['settings'] as $definition) {
                if ($definition->key === $key) {
                    return $definition;
                }
            }
        }

        return null;
    }

    public function isSecret(string $key): bool
    {
        $definition = $this->definition($key);

        return $definition !== null && $definition->secret;
    }
}
