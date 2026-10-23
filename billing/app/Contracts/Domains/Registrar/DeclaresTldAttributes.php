<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Integrations\SettingField;

/**
 * Capability `tld_attributes`: extra details a registry asks for when a name under some extensions is registered
 * (for example the nexus of a `.us` name). Domains asks the driver for the fields of an extension, shows them where
 * the domain is ordered, validates the answers from the same list and stores them per domain; the driver receives
 * them as `RegistrationRequest::$tldAttributes`, keyed by the field keys it declared here.
 *
 * The field list is static knowledge of the driver: no network call, no account settings. Fields are never secret
 * and never take remote options. The values can be personal data, so a driver never logs them or puts them in an
 * error message (the host also masks the free-text ones in whatever a call returns). A field's `help` is shown to the
 * person ordering, also while an error shows: say who qualifies for the extension in plain words.
 */
interface DeclaresTldAttributes
{
    /**
     * @param  string  $tld  the extension with its leading dot, lowercase (`.us`, `.com.au`)
     * @return list<SettingField> empty when the extension needs nothing. `required` makes a field mandatory;
     *                            `FieldType::Boolean` is delivered as `'true'` or `'false'`; secret types are not allowed.
     */
    public function tldAttributeFields(string $tld): array;
}
