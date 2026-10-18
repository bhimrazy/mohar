<?php

namespace App\Data\Services\Provisioning;

/**
 * The account usernames a panel accepts. Without one in DriverMetadata the host applies the default (this class with
 * no arguments: lowercase letters and digits, starting with a letter, at most 8 characters); a driver's policy
 * replaces it. The host applies it when it generates a username and when staff type one.
 */
final readonly class UsernamePolicy
{
    public const TOO_LONG = 'too_long';

    public const INVALID_CHARACTERS = 'invalid_characters';

    public const RESERVED_NAME = 'reserved_name';

    public const RESERVED_PREFIX = 'reserved_prefix';

    public const RESERVED_SUFFIX = 'reserved_suffix';

    /**
     * @param  string  $pattern  a PCRE pattern the whole username must match
     * @param  list<string>  $reservedPrefixes  e.g. `test`
     * @param  list<string>  $reservedSuffixes  e.g. `assword`
     * @param  list<string>  $reservedNames  system names and mail aliases, e.g. `root`, `postmaster`
     */
    public function __construct(
        public int $maxLength = 8,
        public string $pattern = '/^[a-z][a-z0-9]*$/',
        public array $reservedPrefixes = [],
        public array $reservedSuffixes = [],
        public array $reservedNames = [],
    ) {}

    /**
     * Why the username is not allowed, as one of this class's reason constants (the host words it), or null.
     */
    public function violation(string $username): ?string
    {
        return match (true) {
            strlen($username) > $this->maxLength => self::TOO_LONG,
            preg_match($this->pattern, $username) !== 1 => self::INVALID_CHARACTERS,
            in_array($username, $this->reservedNames, true) => self::RESERVED_NAME,
            array_filter($this->reservedPrefixes, fn (string $prefix): bool => str_starts_with($username, $prefix)) !== [] => self::RESERVED_PREFIX,
            array_filter($this->reservedSuffixes, fn (string $suffix): bool => str_ends_with($username, $suffix)) !== [] => self::RESERVED_SUFFIX,
            default => null,
        };
    }
}
