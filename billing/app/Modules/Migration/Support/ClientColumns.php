<?php

namespace App\Modules\Migration\Support;

/**
 * What a file column can become (client-import spec §5.2), the header words that suggest each target, and the
 * columns that are never imported.
 */
final class ClientColumns
{
    public const IGNORE = 'ignore';

    public const IMPORTED_FIELD = 'imported_field';

    public const EXTERNAL_ID = 'external_id';

    /** Targets and their labels, in the order the map step lists them. */
    public const TARGETS = [
        self::EXTERNAL_ID => 'External ID',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'company_name' => 'Company name',
        'email' => 'Email',
        'phone' => 'Phone',
        'address_1' => 'Address line 1',
        'address_2' => 'Address line 2',
        'city' => 'City',
        'state' => 'State or region',
        'postcode' => 'Postcode',
        'country' => 'Country',
        'tax_id' => 'Tax ID',
        'tax_exempt' => 'Tax exempt',
        'currency' => 'Currency',
        'client_group' => 'Client group',
        'language' => 'Language',
        'status' => 'Status',
        'notes' => 'Admin notes',
        'signup_date' => 'Sign-up date',
        'email_verified' => 'Email verified',
        'marketing_consent' => 'Marketing consent',
        'marketing_consent_date' => 'Marketing consent date',
        'marketing_consent_evidence' => 'Marketing consent evidence',
        self::IMPORTED_FIELD => 'Imported field (keep as is)',
        self::IGNORE => 'Ignore',
    ];

    /** Targets a mapping needs before a run can be checked. */
    public const REQUIRED = ['first_name', 'last_name', 'email'];

    /** Targets that take dates, read with the mapping's date format. */
    public const DATES = ['signup_date', 'marketing_consent_date'];

    public const DATE_FORMATS = ['Y-m-d' => '2026-10-31', 'd/m/Y' => '31/10/2026', 'm/d/Y' => '10/31/2026'];

    public const MAX_IMPORTED_FIELDS = 50;

    public const PRESETS = ['whmcs' => 'WHMCS clients export', 'generic' => 'Any spreadsheet'];

    /** Header words (lowercase letters and digits only) that suggest a target, for every preset. */
    private const SYNONYMS = [
        self::EXTERNAL_ID => ['externalid', 'clientid', 'customerid', 'customernumber', 'accountnumber'],
        'first_name' => ['firstname', 'givenname', 'forename', 'fname'],
        'last_name' => ['lastname', 'surname', 'familyname', 'lname'],
        'company_name' => ['company', 'companyname', 'organisation', 'organization', 'business', 'businessname'],
        'email' => ['email', 'emailaddress', 'mail'],
        'phone' => ['phone', 'phonenumber', 'telephone', 'mobile', 'tel'],
        'address_1' => ['address', 'address1', 'addressline1', 'street'],
        'address_2' => ['address2', 'addressline2'],
        'city' => ['city', 'town'],
        'state' => ['state', 'region', 'province', 'county'],
        'postcode' => ['postcode', 'zip', 'zipcode', 'postalcode', 'pincode'],
        'country' => ['country', 'countrycode'],
        'tax_id' => ['taxid', 'vatnumber', 'vatid', 'gstin', 'gstnumber', 'pan', 'taxnumber'],
        'tax_exempt' => ['taxexempt'],
        'currency' => ['currency', 'currencycode'],
        'client_group' => ['clientgroup', 'group', 'customergroup'],
        'language' => ['language', 'locale'],
        'status' => ['status', 'clientstatus'],
        'notes' => ['notes', 'adminnotes', 'note'],
        'signup_date' => ['signupdate', 'datecreated', 'createddate', 'creationdate', 'created', 'registered', 'registrationdate'],
        'email_verified' => ['emailverified', 'verified'],
        'marketing_consent' => ['marketingoptin', 'marketingconsent', 'marketingemailsoptin', 'newsletter'],
    ];

    /** Extra words of the WHMCS client export (headers not confirmed by public docs; client-import spec §3.1, Q11). */
    private const WHMCS = [
        self::EXTERNAL_ID => ['id', 'userid'],
    ];

    /** Passwords, hashes, card and bank data, security answers and second-factor secrets are never imported. */
    private const FORBIDDEN = '/pass(word|wd|code)?\b|pwd|hash|card|\bcc\b|cvv|cvc|security ?(answer|question)|2fa|two ?factor|totp|secret|iban|bank ?account|sort ?code/i';

    public static function isForbidden(string $header): bool
    {
        return preg_match(self::FORBIDDEN, str_replace(['_', '-'], ' ', $header)) === 1;
    }

    /**
     * A suggested target per column: forbidden columns are ignored, an unrecognised header becomes an imported field,
     * and an empty or repeated header gets no suggestion (staff must choose).
     *
     * @param  list<string>  $header
     * @return list<string|null>
     */
    public static function suggest(array $header, string $preset): array
    {
        $taken = [];
        $counts = array_count_values(array_map(self::key(...), $header));

        return array_map(function (string $name) use (&$taken, $counts, $preset): ?string {
            $key = self::key($name);

            if (self::isForbidden($name)) {
                return self::IGNORE;
            }

            if ($key === '' || $counts[$key] > 1) {
                return null;
            }

            foreach ([...($preset === 'whmcs' ? [self::WHMCS] : []), self::SYNONYMS] as $synonyms) {
                foreach ($synonyms as $target => $words) {
                    if (in_array($key, $words, true) && ! isset($taken[$target])) {
                        $taken[$target] = true;

                        return $target;
                    }
                }
            }

            return self::IMPORTED_FIELD;
        }, $header);
    }

    private static function key(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));
    }
}
