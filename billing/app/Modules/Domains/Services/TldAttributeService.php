<?php

namespace App\Modules\Domains\Services;

use App\Contracts\Domains\Registrar\DeclaresTldAttributes;
use App\Contracts\Domains\Registrar\ValidatesTldAttributes;
use App\Contracts\Domains\TldAttributes;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Integrations\Redactor;
use Closure;
use Error;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Throwable;

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

    /** Nesting of `meta` and `remote` that is masked; anything deeper is replaced whole rather than shown unchecked. */
    private const MAX_DEPTH = 8;

    /** Characters a registry may drop, add or move when it echoes an identifier: space, dash, dot, slash, underscore. */
    private const SEPARATORS = '[\\s\\-.\\/_]';

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
            throw ValidationException::withMessages(['tld_attributes' => __('We cannot take :tld registrations right now. Please try again later.', ['tld' => $model->extension])]);
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

        // The driver's own rules run with the generic ones, so every problem shows at once. Its error on a declared
        // key counts even when the answer was left empty; a field that already has an error keeps that message.
        if (($driver = $this->drivers->get((string) $model->registrar_key)) instanceof ValidatesTldAttributes) {
            $declared = array_map(fn (SettingField $field): string => $field->key, $fields);

            foreach ($driver->validateTldAttributes($model->extension, $clean) as $key => $message) {
                if (in_array($key, $declared, true)) {
                    $errors['tld_attributes.'.$key] ??= $message;
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
     * `P1` or `true` are not masked: they would hide ordinary words). Each value is masked as typed, without its
     * separators (spaces, dashes, dots, slashes) and in upper and lower case, so the gateway's exact-match masking
     * (which also covers the URL-encoded forms) catches the usual echoes. Regrouped echoes are caught by `guard()`.
     *
     * A value of fewer than 4 characters is not masked, as everywhere else: it would hide ordinary words in the
     * error text (false positives), and a 3-character answer is not an identifier worth that.
     *
     * @param  array<string, string>  $answers
     * @return list<string>
     */
    public function maskable(string $tld, array $answers): array
    {
        $values = [];

        foreach ($this->freeText($tld, $answers) as $value) {
            $stripped = self::stripped($value);

            foreach ([$value, $stripped, mb_strtolower($value), mb_strtoupper($value), mb_strtolower($stripped), mb_strtoupper($stripped)] as $variant) {
                if (mb_strlen($variant) >= self::MIN_MASKED) {
                    $values[] = $variant;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Runs a registrar call and masks the free-text answers in what it raises or returns (the message, `meta` and `remote` of a result), however the registry
     * echoed them: regrouped (`518 247 535 56`), dashed, dotted, slashed or in another case. The gateway then masks
     * the exact forms again. The message of the exception and of each exception behind it is rewritten in place,
     * keeping its class and flags.
     *
     * @template T
     *
     * @param  array<string, string>  $answers
     * @param  Closure(): T  $call
     * @return T
     */
    public function guard(string $tld, array $answers, Closure $call): mixed
    {
        $patterns = $this->patterns($tld, $answers);

        if ($patterns === []) {
            return $call();
        }

        try {
            $result = $call();
        } catch (Throwable $exception) {
            for ($link = $exception, $hops = 0; $link !== null && $hops < 16; $link = $link->getPrevious(), $hops++) {
                $masked = $this->maskText($patterns, $link->getMessage());

                if ($masked !== $link->getMessage()) {
                    (new ReflectionProperty($link instanceof Exception ? Exception::class : Error::class, 'message'))->setValue($link, $masked);
                }
            }

            throw $exception;
        }

        return $result instanceof RegistrarResult ? $result->mapText(fn (string $text): string => $this->maskText($patterns, $text), Redactor::MASK, self::MAX_DEPTH) : $result;
    }

    /**
     * Invalid UTF-8 from the registry is scrubbed first: a `/u` pattern fails on it and would otherwise blank the whole
     * message. If a pattern still fails, the message is withheld rather than shown unmasked.
     *
     * @param  list<string>  $patterns
     */
    private function maskText(array $patterns, string $text): string
    {
        return preg_replace($patterns, Redactor::MASK, mb_scrub($text, 'UTF-8')) ?? Redactor::MASK;
    }

    /**
     * One case-insensitive pattern per free-text answer: its letters and digits in order, with any run of
     * separators between them.
     *
     * @param  array<string, string>  $answers
     * @return list<string>
     */
    private function patterns(string $tld, array $answers): array
    {
        $patterns = [];

        foreach ($this->freeText($tld, $answers) as $value) {
            $core = preg_split('//u', self::stripped($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($core) >= self::MIN_MASKED) {
                $patterns[] = '/'.implode(self::SEPARATORS.'*', array_map(fn (string $char): string => preg_quote($char, '/'), $core)).'/iu';
            }
        }

        return $patterns;
    }

    /**
     * @param  array<string, string>  $answers
     * @return list<string>
     */
    private function freeText(string $tld, array $answers): array
    {
        $model = $this->tld($tld);
        $values = [];

        foreach ($model === null ? [] : $this->declared($model) as $field) {
            if (! in_array($field->type, [FieldType::Select, FieldType::Boolean], true) && isset($answers[$field->key]) && $answers[$field->key] !== '') {
                $values[] = $answers[$field->key];
            }
        }

        return $values;
    }

    private static function stripped(string $value): string
    {
        return (string) preg_replace('/'.self::SEPARATORS.'+/u', '', $value);
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
