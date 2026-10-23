<?php

namespace App\Support;

/**
 * E.164 country calling codes (ITU assignments) and a splitter for free-text phone numbers.
 *
 * The table holds country codes only (1 to 3 digits), so no code is a prefix of another. Area codes inside
 * shared codes (+1 NANP, +7, +44 crown dependencies) are not modelled; those countries share one entry.
 */
final class CallingCodes
{
    /** Calling code => ISO 3166-1 alpha-2 countries sharing it, the main one first. */
    public const TABLE = [
        '1' => 'US CA AG AI AS BB BM BS DM DO GD GU JM KN KY LC MP MS PR SX TC TT UM VC VG VI',
        '7' => 'RU KZ',
        '20' => 'EG', '27' => 'ZA', '30' => 'GR', '31' => 'NL', '32' => 'BE', '33' => 'FR', '34' => 'ES', '36' => 'HU',
        '39' => 'IT VA', '40' => 'RO', '41' => 'CH', '43' => 'AT', '44' => 'GB GG IM JE', '45' => 'DK', '46' => 'SE',
        '47' => 'NO SJ BV', '48' => 'PL', '49' => 'DE', '51' => 'PE', '52' => 'MX', '53' => 'CU', '54' => 'AR',
        '55' => 'BR', '56' => 'CL', '57' => 'CO', '58' => 'VE', '60' => 'MY', '61' => 'AU CC CX', '62' => 'ID',
        '63' => 'PH', '64' => 'NZ PN', '65' => 'SG', '66' => 'TH', '81' => 'JP', '82' => 'KR', '84' => 'VN', '86' => 'CN',
        '90' => 'TR', '91' => 'IN', '92' => 'PK', '93' => 'AF', '94' => 'LK', '95' => 'MM', '98' => 'IR',
        '211' => 'SS', '212' => 'MA EH', '213' => 'DZ', '216' => 'TN', '218' => 'LY', '220' => 'GM', '221' => 'SN',
        '222' => 'MR', '223' => 'ML', '224' => 'GN', '225' => 'CI', '226' => 'BF', '227' => 'NE', '228' => 'TG',
        '229' => 'BJ', '230' => 'MU', '231' => 'LR', '232' => 'SL', '233' => 'GH', '234' => 'NG', '235' => 'TD',
        '236' => 'CF', '237' => 'CM', '238' => 'CV', '239' => 'ST', '240' => 'GQ', '241' => 'GA', '242' => 'CG',
        '243' => 'CD', '244' => 'AO', '245' => 'GW', '246' => 'IO', '248' => 'SC', '249' => 'SD', '250' => 'RW',
        '251' => 'ET', '252' => 'SO', '253' => 'DJ', '254' => 'KE', '255' => 'TZ', '256' => 'UG', '257' => 'BI',
        '258' => 'MZ', '260' => 'ZM', '261' => 'MG', '262' => 'RE YT TF', '263' => 'ZW', '264' => 'NA', '265' => 'MW',
        '266' => 'LS', '267' => 'BW', '268' => 'SZ', '269' => 'KM', '290' => 'SH', '291' => 'ER', '297' => 'AW',
        '298' => 'FO', '299' => 'GL',
        '350' => 'GI', '351' => 'PT', '352' => 'LU', '353' => 'IE', '354' => 'IS', '355' => 'AL', '356' => 'MT',
        '357' => 'CY', '358' => 'FI AX', '359' => 'BG', '370' => 'LT', '371' => 'LV', '372' => 'EE', '373' => 'MD',
        '374' => 'AM', '375' => 'BY', '376' => 'AD', '377' => 'MC', '378' => 'SM', '380' => 'UA', '381' => 'RS',
        '382' => 'ME', '383' => 'XK', '385' => 'HR', '386' => 'SI', '387' => 'BA', '389' => 'MK', '420' => 'CZ',
        '421' => 'SK', '423' => 'LI',
        '500' => 'FK GS', '501' => 'BZ', '502' => 'GT', '503' => 'SV', '504' => 'HN', '505' => 'NI', '506' => 'CR',
        '507' => 'PA', '508' => 'PM', '509' => 'HT', '590' => 'GP BL MF', '591' => 'BO', '592' => 'GY', '593' => 'EC',
        '594' => 'GF', '595' => 'PY', '596' => 'MQ', '597' => 'SR', '598' => 'UY', '599' => 'CW BQ',
        '670' => 'TL', '672' => 'NF AQ HM', '673' => 'BN', '674' => 'NR', '675' => 'PG', '676' => 'TO', '677' => 'SB',
        '678' => 'VU', '679' => 'FJ', '680' => 'PW', '681' => 'WF', '682' => 'CK', '683' => 'NU', '685' => 'WS',
        '686' => 'KI', '687' => 'NC', '688' => 'TV', '689' => 'PF', '690' => 'TK', '691' => 'FM', '692' => 'MH',
        '850' => 'KP', '852' => 'HK', '853' => 'MO', '855' => 'KH', '856' => 'LA', '880' => 'BD', '886' => 'TW',
        '960' => 'MV', '961' => 'LB', '962' => 'JO', '963' => 'SY', '964' => 'IQ', '965' => 'KW', '966' => 'SA',
        '967' => 'YE', '968' => 'OM', '970' => 'PS', '971' => 'AE', '972' => 'IL', '973' => 'BH', '974' => 'QA',
        '975' => 'BT', '976' => 'MN', '977' => 'NP', '992' => 'TJ', '993' => 'TM', '994' => 'AZ', '995' => 'GE',
        '996' => 'KG', '998' => 'UZ',
    ];

    /** E.164 allows 15 digits in all; the national part needs at least 4 to be a plausible subscriber number. */
    private const MAX_DIGITS = 15;

    private const MIN_NATIONAL = 4;

    /**
     * The calling code of a country, or null when it has none.
     */
    public static function forCountry(string $country): ?string
    {
        $country = strtoupper(trim($country));

        foreach (self::TABLE as $code => $countries) {
            if (in_array($country, explode(' ', $countries), true)) {
                return (string) $code;
            }
        }

        return null;
    }

    /**
     * Countries sharing a calling code, the main one first.
     *
     * @return list<string>
     */
    public static function countries(string $code): array
    {
        return isset(self::TABLE[ltrim($code, '+')]) ? explode(' ', self::TABLE[ltrim($code, '+')]) : [];
    }

    /**
     * Split a free-text phone into calling code and national number (digits only, no trunk 0).
     *
     * Input with "+" or "00" is international and its prefix is matched against the table; anything else is a
     * national number and takes the code of $country. Returns null when the text is not a phone number, the
     * code is unknown, or no code can be chosen. Never throws.
     *
     * @return array{code: string, national: string}|null
     */
    public static function split(string $phone, ?string $country = null): ?array
    {
        $phone = trim($phone);

        if ($phone === '' || preg_match('/^\+?[\d\s.\-()]+$/', $phone) !== 1) {
            return null;
        }

        $rest = preg_replace('/^(?:\+|00)/', '', $phone, 1, $count);
        $international = $count === 1;

        if ($international) {
            // "+44 (0) 20 ..." writes the trunk zero in brackets; it is not part of the number.
            $digits = (string) preg_replace('/\D/', '', (string) preg_replace('/\(\s*0\s*\)/', '', (string) $rest));
            $code = self::codeOf($digits);
            $national = $code === null ? '' : substr($digits, strlen($code));
        } else {
            $code = $country === null ? null : self::forCountry($country);
            $national = ltrim((string) preg_replace('/\D/', '', $phone), '0');
        }

        if ($code === null || strlen($national) < self::MIN_NATIONAL || strlen($code) + strlen($national) > self::MAX_DIGITS) {
            return null;
        }

        return ['code' => $code, 'national' => $national];
    }

    /** The table is prefix-free, so at most one of the first three digits matches. */
    private static function codeOf(string $digits): ?string
    {
        foreach ([1, 2, 3] as $length) {
            $candidate = substr($digits, 0, $length);

            if (isset(self::TABLE[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }
}
