<?php

namespace App\Contracts\Domains\Registrar;

/**
 * Optional refinement of `DeclaresTldAttributes` for rules between fields that a field list cannot express (an
 * identification number whose length depends on its type). Domains calls it after the generic checks passed, at the
 * cart and again when the pending domain is created. Not a separate capability name: it only sharpens the answers.
 */
interface ValidatesTldAttributes extends DeclaresTldAttributes
{
    /**
     * @param  string  $tld  the extension with its leading dot, lowercase
     * @param  array<string, string>  $answers  already checked against the declared fields
     * @return array<string, string> field key => plain message for the person who typed it; empty when acceptable.
     *                               The message names the field by its label and never repeats the value.
     */
    public function validateTldAttributes(string $tld, array $answers): array;
}
