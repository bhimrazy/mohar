<?php

namespace App\Contracts\Domains;

use App\Data\Integrations\SettingField;
use Illuminate\Validation\ValidationException;

/**
 * Implemented by the Domains module. Used by Orders, which asks it where a domain is ordered: which extra details
 * the registrar wants for an extension, and whether the answers are acceptable (`DeclaresTldAttributes`, ADR 0009
 * Amendment 2). Orders works without it: when nothing implements it, no extension has fields.
 */
interface TldAttributes
{
    /**
     * Fields to ask for, by extension (with its leading dot), only for extensions that have some.
     *
     * @return array<string, list<SettingField>>
     */
    public function fieldsByTld(): array;

    /**
     * Answers for the given extension, checked against its fields: unknown keys are dropped, a missing required
     * answer or a value outside a field's options is refused.
     *
     * @param  string  $tld  extension with its leading dot
     * @return array<string, string>
     *
     * @throws ValidationException errors keyed `tld_attributes.<field key>`
     */
    public function clean(string $tld, mixed $input): array;
}
