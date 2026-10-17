<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Core\Support\Settings;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Validation\Rule;

/**
 * Reads the SettingsRegistry for the generic settings UI: which sections a staff member may see
 * or edit, current values (secrets never leave the server) and validation rules per definition.
 */
class SettingsCatalog
{
    /** Permission for sections registered without their own permission. */
    public const DEFAULT_PERMISSION = 'core.settings.update';

    public function __construct(private SettingsRegistry $registry, private Settings $settings, private PermissionRegistry $permissions) {}

    /**
     * Sections guarded by a sensitive permission need a recent password confirmation to change.
     */
    public function requiresConfirmation(string $section): bool
    {
        $definition = $this->section($section);

        return $definition !== null && $this->permissions->isSensitive($definition['permission'] ?? self::DEFAULT_PERMISSION);
    }

    /**
     * @return array<string, array{title: string, permission: ?string, settings: list<SettingDefinition>}>
     */
    public function visibleSections(User $user): array
    {
        return array_filter(
            $this->registry->sections(),
            fn (array $section): bool => $user->hasPermission('core.settings.view') || $user->hasPermission($section['permission'] ?? self::DEFAULT_PERMISSION),
        );
    }

    /**
     * @return array{title: string, permission: ?string, settings: list<SettingDefinition>}|null
     */
    public function section(string $key): ?array
    {
        return $this->registry->sections()[$key] ?? null;
    }

    public function canEdit(User $user, string $section): bool
    {
        $definition = $this->section($section);

        return $definition !== null && $user->hasPermission($definition['permission'] ?? self::DEFAULT_PERMISSION);
    }

    /**
     * @return list<array{key: string, label: string, type: string, help: ?string, options: list<array{value: string, label: string}>, secret: bool, value: mixed, is_set: bool}>
     */
    public function fields(string $section): array
    {
        return array_map(function (SettingDefinition $definition): array {
            $value = $this->settings->get($definition->key);

            return [
                'key' => $definition->key,
                'label' => $definition->label,
                'type' => $definition->type,
                'help' => $definition->help,
                'options' => array_map(
                    fn (string|int $value, string $label): array => ['value' => (string) $value, 'label' => $label],
                    array_keys($definition->options),
                    array_values($definition->options),
                ),
                'secret' => $definition->secret,
                'value' => $definition->secret ? null : $value,
                'is_set' => $value !== null && $value !== '',
            ];
        }, $this->section($section)['settings'] ?? []);
    }

    /**
     * Validation rules keyed by setting key (dot notation maps onto nested request input).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(string $section): array
    {
        $rules = [];

        foreach ($this->section($section)['settings'] ?? [] as $definition) {
            $base = match ($definition->type) {
                'integer' => ['integer'],
                'boolean' => ['boolean'],
                'select' => [Rule::in(array_map('strval', array_keys($definition->options)))],
                'email' => ['string', 'email', 'max:255'],
                'text' => ['string', 'max:10000'],
                default => ['string', 'max:255'],
            };
            $presence = in_array($definition->type, ['integer', 'boolean', 'select'], true) || in_array('required', $definition->rules, true)
                ? 'required'
                : 'nullable';

            $rules[$definition->key] = $definition->secret
                ? ['nullable', ...$base, ...array_values(array_diff($definition->rules, ['required']))]
                : [$presence, ...$base, ...array_values(array_diff($definition->rules, ['required']))];
        }

        return $rules;
    }

    public function cast(SettingDefinition $definition, mixed $value): mixed
    {
        return match ($definition->type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value === null ? null : (string) $value,
        };
    }
}
