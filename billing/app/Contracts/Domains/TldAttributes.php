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
     * @throws ValidationException errors keyed `tld_attributes.<field key>`; when the registrar cannot take orders
     *                             for the extension now (its account is disabled), one error keyed `domain`
     */
    public function clean(string $tld, mixed $input): array;

    /**
     * The answers as a person reads them: field label and the chosen option's label, a yes for a ticked box, and a
     * free-text value (an identification number) shortened to its last three characters when $mask is set.
     *
     * @param  array<string, string>  $answers  as returned by clean()
     * @return list<array{label: string, value: string}>
     */
    public function describe(string $tld, array $answers, bool $mask = true): array;
}
