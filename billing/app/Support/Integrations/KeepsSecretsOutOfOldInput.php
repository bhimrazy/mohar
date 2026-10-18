<?php

namespace App\Support\Integrations;

use App\Data\Integrations\SettingField;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * For form requests that carry integration secrets: when validation fails, the redirect flashes the old input
 * without them, so a secret is never written to the session (security standards §6). Which inputs are secret comes
 * from the form's SettingField definitions, not from a list of names.
 *
 * @api
 */
trait KeepsSecretsOutOfOldInput
{
    /**
     * Input paths (dot notation) that must never be flashed.
     *
     * @return list<string>
     */
    abstract protected function secretInputPaths(): array;

    /**
     * Every submitted key under $prefix except the non-secret fields declared there: secret fields and anything
     * undeclared stay out of the session.
     *
     * @param  list<SettingField>  $fields
     * @return list<string>
     */
    protected function settingInputPaths(string $prefix, array $fields): array
    {
        $public = array_map(fn (SettingField $field): string => $field->key, array_filter($fields, fn (SettingField $field): bool => ! $field->isSecret()));
        $submitted = array_map('strval', array_keys((array) $this->input($prefix, [])));

        return array_values(array_map(fn (string $key): string => "{$prefix}.{$key}", array_diff($submitted, $public)));
    }

    protected function failedValidation(Validator $validator): void
    {
        $input = Arr::except($this->input(), [...$this->secretInputPaths(), 'password', 'password_confirmation', 'current_password']);
        $response = redirect()->to($this->getRedirectUrl())->withInput($input)->withErrors($validator->errors(), $this->errorBag);

        throw new ValidationException($validator, $response, $this->errorBag);
    }
}
