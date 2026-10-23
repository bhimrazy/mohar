<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Domains\Registrar\DeclaresTldAttributes;
use App\Contracts\Domains\Registrar\ValidatesTldAttributes;
use App\Contracts\Domains\TldAttributes;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Extension details the registrar asks for (ADR 0009 Amendments 2 and 3). The field list comes from the extension's
 * registrar driver (`DeclaresTldAttributes`); this class keeps no list of its own. The same list renders the form and
 * validates what comes back, so a value the form could not have offered is refused.
 *
 * Bound scoped: the capable and disabled registrar keys and each extension's fields are worked out once per request,
 * so a page with hundreds of extensions runs a fixed number of queries. A disabled registrar account only hides the
 * form and stops new orders for the extension; it never makes answers that were collected vanish.
 *
 * Values may be personal data: they are never logged, and error messages name the field, not the value.
 */
class TldAttributeService implements TldAttributes
{
    private const MAX_LENGTH = 255;

    /** Shortest free-text value the host masks in registrar errors (shorter ones would mask ordinary words). */
    private const MIN_MASKED = 4;

    /** @var list<string>|null */
    private ?array $capable = null;

    /** @var list<string>|null */
    private ?array $disabled = null;

    /** @var array<string, list<SettingField>>|null */
    private ?array $byTld = null;

    /** @var array<string, Tld|null> */
    private array $tlds = [];

    public function __construct(private RegistrarDriverRegistry $drivers) {}

    public function fieldsByTld(): array
    {
        if ($this->byTld !== null) {
            return $this->byTld;
        }

        $this->byTld = [];
        $keys = array_values(array_diff($this->capableKeys(), $this->disabledKeys()));

        if ($keys === []) {
            return $this->byTld;
        }

        Tld::query()->active()->whereIn('registrar_key', $keys)->where('allow_register', true)->orderBy('id')->limit(1000)->get()
            ->each(function (Tld $tld): void {
                if (($list = $this->declared($tld)) !== []) {
                    $this->byTld[$tld->extension] = $list;
                }
            });

        return $this->byTld;
    }

    /**
     * What the extension's registrar declares, whatever the state of its account.
     *
     * @return list<SettingField>
     */
    public function declared(Tld $tld): array
    {
        $key = $tld->registrar_key;

        if ($key === null || $tld->registered_by_client || ! in_array($key, $this->capableKeys(), true)) {
            return [];
        }

        $driver = $this->drivers->get($key);

        if (! $driver instanceof DeclaresTldAttributes) {
            return [];
        }

        return array_values(array_filter(
            $driver->tldAttributeFields($tld->extension),
            fn (SettingField $field): bool => ! $field->isSecret() && $field->optionsSource === null,
        ));
    }

    public function clean(string $tld, mixed $input): array
    {
        $model = $this->tld($tld);
        $fields = $model === null ? [] : $this->declared($model);

        if ($fields === []) {
            return [];
        }

        if (in_array($model->registrar_key, $this->disabledKeys(), true)) {
            throw ValidationException::withMessages(['domain' => __('We cannot take :tld registrations right now. Please try again later.', ['tld' => $model->extension])]);
        }

        $input = is_array($input) ? $input : [];
        $clean = [];
        $errors = [];

        foreach ($fields as $field) {
            $raw = $input[$field->key] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $name = 'tld_attributes.'.$field->key;
            $label = Str::lcfirst($field->label);

            if ($field->type === FieldType::Boolean) {
                $on = in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);

                if ($field->required && ! $on) {
                    $errors[$name] = __('Tick the box to confirm.');
                } else {
                    $clean[$field->key] = $on ? 'true' : 'false';
                }

                continue;
            }

            if ($value === '') {
                if ($field->required) {
                    $errors[$name] = $field->type === FieldType::Select ? __('Choose :field.', ['field' => $label]) : __('Enter :field.', ['field' => $label]);
                }

                continue;
            }

            $error = match (true) {
                ! mb_check_encoding($value, 'UTF-8'), preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1 => __(':field has characters we cannot accept. Remove them and try again.', ['field' => ucfirst($field->label)]),
                mb_strlen($value) > self::MAX_LENGTH => __(':field can be up to :max characters.', ['field' => ucfirst($field->label), 'max' => self::MAX_LENGTH]),
                $field->type === FieldType::Select && ! array_key_exists($value, $field->options) => __('Choose one of the listed options for :field.', ['field' => $label]),
                $field->type === FieldType::Number && ! is_numeric($value) => __('Enter a number for :field.', ['field' => $label]),
                $field->type === FieldType::Url && filter_var($value, FILTER_VALIDATE_URL) === false => __('Enter a full web address for :field.', ['field' => $label]),
                default => null,
            };

            if ($error !== null) {
                $errors[$name] = $error;
            } else {
                $clean[$field->key] = $value;
            }
        }

        if ($errors === [] && ($driver = $this->drivers->get((string) $model->registrar_key)) instanceof ValidatesTldAttributes) {
            foreach ($driver->validateTldAttributes($model->extension, $clean) as $key => $message) {
                if (array_key_exists($key, $clean)) {
                    $errors['tld_attributes.'.$key] = $message;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    public function describe(string $tld, array $answers, bool $mask = true): array
    {
        $model = $this->tld($tld);
        $rows = [];

        foreach ($model === null ? [] : $this->declared($model) as $field) {
            $value = $answers[$field->key] ?? null;

            if ($value === null || $value === '' || ($field->type === FieldType::Boolean && $value !== 'true')) {
                continue;
            }

            $shown = match ($field->type) {
                FieldType::Select => $field->options[$value] ?? $value,
                FieldType::Boolean => __('Yes'),
                default => $mask ? self::masked($value) : $value,
            };

            $rows[] = ['label' => $field->label, 'value' => (string) $shown];
        }

        return $rows;
    }

    /**
     * The free-text answers the host masks in anything a registrar call returns (select and boolean answers such as
     * `P1` or `true` are not masked: they would hide ordinary words). A value typed with spaces is also masked
     * without them, as registries often echo it normalised.
     *
     * @param  array<string, string>  $answers
     * @return list<string>
     */
    public function maskable(string $tld, array $answers): array
    {
        $model = $this->tld($tld);
        $values = [];

        foreach ($model === null ? [] : $this->declared($model) as $field) {
            if (in_array($field->type, [FieldType::Select, FieldType::Boolean], true) || ! isset($answers[$field->key])) {
                continue;
            }

            foreach ([$answers[$field->key], (string) preg_replace('/\s+/u', '', $answers[$field->key])] as $value) {
                if (mb_strlen($value) >= self::MIN_MASKED) {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }

    private static function masked(string $value): string
    {
        $length = mb_strlen($value);

        return $length <= 3 ? str_repeat('•', $length) : str_repeat('•', min($length - 3, 12)).mb_substr($value, -3);
    }

    private function tld(string $extension): ?Tld
    {
        $extension = Tld::normaliseExtension($extension);

        return $this->tlds[$extension] ??= Tld::query()->where('extension', $extension)->first();
    }

    /**
     * @return list<string>
     */
    private function capableKeys(): array
    {
        return $this->capable ??= array_values(array_filter($this->drivers->keys(), fn (string $key): bool => $this->drivers->supports($key, RegistrarDriverRegistry::TLD_ATTRIBUTES)));
    }

    /**
     * Capable registrars whose account exists and is switched off (no account counts as on, as in isActive()).
     *
     * @return list<string>
     */
    private function disabledKeys(): array
    {
        return $this->disabled ??= $this->capableKeys() === [] ? [] : array_values(array_map(strval(...), RegistrarAccount::query()->whereIn('key', $this->capableKeys())->where('is_active', false)->pluck('key')->all()));
    }
}
