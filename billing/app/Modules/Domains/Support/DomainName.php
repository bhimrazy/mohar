<?php

namespace App\Modules\Domains\Support;

use InvalidArgumentException;

/**
 * Pure domain-name normalisation and validation (no framework dependencies).
 *
 * Names are stored and compared in their ASCII (punycode) form and displayed in Unicode.
 */
final class DomainName
{
    public const MAX_LENGTH = 253;

    public const MAX_LABEL_LENGTH = 63;

    /**
     * Turn user input ("HTTPS://WWW.Example.COM/path") into a bare lowercase host ("example.com").
     * Does not validate; see toAscii() for that.
     */
    public static function clean(string $input): string
    {
        $value = trim($input);
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value);
        $value = (string) preg_replace('#[/?\#].*$#', '', $value);
        $value = (string) preg_replace('#^[^@]*@#', '', $value);
        $value = (string) preg_replace('#:\d+$#', '', $value);
        $value = mb_strtolower(trim($value));
        $value = rtrim($value, '.');
        $value = str_replace(['。', '．', '｡'], '.', $value);

        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }

        return $value;
    }

    /**
     * Clean, convert IDN labels to punycode and validate. Returns the ASCII form.
     *
     * @throws InvalidArgumentException when the name is not a valid hostname
     */
    public static function toAscii(string $input, bool $allowIdn = true): string
    {
        $value = self::clean($input);

        if ($value === '') {
            throw new InvalidArgumentException('Enter a domain name.');
        }

        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            if (! $allowIdn) {
                throw new InvalidArgumentException('Internationalised domain names are not supported.');
            }

            $ascii = idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                throw new InvalidArgumentException('Enter a valid domain name.');
            }

            $value = strtolower($ascii);
        }

        self::assertValidAscii($value);

        return $value;
    }

    /**
     * Unicode form of an ASCII name, for display. Falls back to the input when it cannot be decoded.
     */
    public static function toUnicode(string $ascii): string
    {
        if (! str_contains($ascii, 'xn--')) {
            return $ascii;
        }

        $unicode = idn_to_utf8($ascii, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $unicode === false ? $ascii : $unicode;
    }

    public static function isValid(string $input, bool $allowIdn = true): bool
    {
        try {
            self::toAscii($input, $allowIdn);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Whether a single label (no dots) is a valid LDH label.
     */
    public static function isValidLabel(string $label): bool
    {
        $length = strlen($label);

        if ($length < 1 || $length > self::MAX_LABEL_LENGTH) {
            return false;
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
            return false;
        }

        // Hyphens in positions 3-4 are reserved for A-labels ("xn--").
        return substr($label, 2, 2) !== '--' || str_starts_with($label, 'xn--');
    }

    /**
     * Validate a hostname used as a nameserver (at least two labels).
     */
    public static function isValidHostname(string $hostname): bool
    {
        try {
            $ascii = self::toAscii($hostname);
        } catch (InvalidArgumentException) {
            return false;
        }

        return str_contains($ascii, '.');
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertValidAscii(string $value): void
    {
        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Domain names can be at most 253 characters long.');
        }

        foreach (explode('.', $value) as $label) {
            if (! self::isValidLabel($label)) {
                throw new InvalidArgumentException('Enter a valid domain name (letters, numbers and hyphens; labels up to 63 characters).');
            }
        }
    }
}
