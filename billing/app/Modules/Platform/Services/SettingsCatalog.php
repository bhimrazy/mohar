<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Support\Countries;
use App\Support\Money;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingLocks;
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

    public function __construct(private SettingsRegistry $registry, private Settings $settings, private PermissionRegistry $permissions, private SettingLocks $locks) {}

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
     * @return list<array{key: string, label: string, type: string, help: ?string, options: list<array{value: string, label: string}>, secret: bool, required: bool, value: mixed, is_set: bool, locked_reason: ?string, currency: ?string}>
     */
    public function fields(string $section): array
    {
        $this->locks->flush();
        $currency = null;

        return array_map(function (SettingDefinition $definition) use (&$currency): array {
            $stored = $this->settings->get($definition->key);
            $value = $this->current($definition);
            $options = $definition->options();

            if ($definition->type === 'money') {
                $currency ??= Currency::default()->code;
            }

            return [
                'key' => $definition->key,
                'label' => $definition->label,
                'type' => $definition->type,
                'help' => $definition->help,
                'locked_reason' => $this->locks->reason($definition->key),
                'options' => array_map(
                    fn (string|int $value, string $label): array => ['value' => (string) $value, 'label' => $label],
                    array_keys($options),
                    array_values($options),
                ),
                'secret' => $definition->secret,
                'required' => $this->isRequired($definition),
                'value' => match (true) {
                    $definition->secret => null,
                    $definition->type === 'money' => is_int($value) ? number_format($value / 100, 2, '.', '') : '',
                    $definition->type === 'country' && is_string($value) && $value !== '' => Countries::normalize($value),
                    default => $value,
                },
                'is_set' => $stored !== null && $stored !== '' && $stored !== [],
                // Money settings are entered in the default currency's units.
                'currency' => $definition->type === 'money' ? $currency : null,
            ];
        }, $this->section($section)['settings'] ?? []);
    }

    /**
     * The stored value in the shape a save produces, so a save compares like with like: a multiselect stored as
     * "a,b" (config or an older save) reads as ['a', 'b'], keeping only known options in option order, and a text
     * setting holding a list reads as "a, b" (one item per line for a text area).
     */
    public function current(SettingDefinition $definition): mixed
    {
        $value = $this->settings->get($definition->key);

        return match ($definition->type) {
            'multiselect' => $this->cast($definition, is_string($value) ? explode(',', $value) : $value),
            'money' => is_numeric($value) ? (int) $value : null,
            // A list default from config ([3, 7]) is edited as "3, 7"; sent as an array, the form showed it blank.
            'string', 'text' => is_array($value) ? implode($definition->type === 'text' ? "\n" : ', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)) : $value,
            default => $value,
        };
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
                'select' => [Rule::in(array_map('strval', array_keys($definition->options())))],
                'multiselect' => ['array'],
                'money' => ['numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
                'country' => ['string', $this->countryRule($definition)],
                'email' => ['string', 'email', 'max:255'],
                'text' => ['string', 'max:10000'],
                default => ['string', 'max:255'],
            };
            $extra = array_values(array_filter($definition->rules, fn (mixed $rule): bool => $rule !== 'required'));
            $presence = $this->isRequired($definition) ? 'required' : 'nullable';

            $rules[$definition->key] = $definition->secret
                ? ['nullable', ...$base, ...$extra]
                : [$presence, ...$base, ...$extra];

            if ($definition->type === 'multiselect') {
                $rules[$definition->key.'.*'] = ['string', Rule::in(array_map('strval', array_keys($definition->options())))];
            }
        }

        return $rules;
    }

    /** Whether the server refuses a blank for this setting (the form marks the field required). */
    private function isRequired(SettingDefinition $definition): bool
    {
        return in_array($definition->type, ['integer', 'boolean', 'select', 'money'], true) || in_array('required', $definition->rules, true);
    }

    /**
     * A country setting takes a known code. A code already stored (an import or an older record) is accepted as it is
     * so it cannot block saving the rest of the section.
     */
    private function countryRule(SettingDefinition $definition): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($definition): void {
            $stored = $this->settings->get($definition->key);
            $unchanged = is_string($value) && is_string($stored) && strcasecmp(trim($value), $stored) === 0;

            if (! $unchanged && ! (is_string($value) && Countries::exists($value))) {
                $fail(__('The :attribute must be a country from the list.'));
            }
        };
    }

    public function cast(SettingDefinition $definition, mixed $value): mixed
    {
        return match ($definition->type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'country' => $value === null || $value === '' ? null : Countries::normalize((string) $value),
            'multiselect' => array_values(array_filter(
                array_map('strval', array_keys($definition->options())),
                fn (string $option): bool => in_array($option, array_map(fn (mixed $item): string => trim(is_scalar($item) ? (string) $item : ''), (array) $value), true),
            )),
            // Major units to minor units, as Money::fromDecimal; the currency plays no part in the conversion.
            'money' => Money::fromDecimal(is_numeric($value) ? $value : 0, 'XXX')->amount,
            default => $value === null ? null : (string) $value,
        };
    }
}
