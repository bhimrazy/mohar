<?php

namespace App\Modules\Migration\Support;

/**
 * ISO 3166-1 alpha-2 country codes, as clients store them (plus XK, widely used for Kosovo).
 */
final class Countries
{
    private const CODES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ '
        .'CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR '
        .'GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP '
        .'KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT '
        .'MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW '
        .'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG '
        .'UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW';

    /** Codes people use that ISO spells differently. */
    private const ALIASES = ['UK' => 'GB'];

    /**
     * The ISO code for a cell, or null when it is not one.
     */
    public static function code(string $value): ?string
    {
        $code = self::ALIASES[$code = strtoupper(trim($value))] ?? $code;

        return strlen($code) === 2 && str_contains(' '.self::CODES.' ', " {$code} ") ? $code : null;
    }
}
