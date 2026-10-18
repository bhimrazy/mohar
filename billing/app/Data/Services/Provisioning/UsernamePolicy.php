<?php

namespace App\Data\Services\Provisioning;

/**
 * The account usernames a panel accepts. The host applies it when it generates a username and when staff type one,
 * on top of its own rule (lowercase letters and digits, starting with a letter, at most 8 characters).
 */
final readonly class UsernamePolicy
{
    /**
     * @param  string  $pattern  a PCRE pattern the whole username must match
     * @param  list<string>  $reservedPrefixes  e.g. `test`
     * @param  list<string>  $reservedSuffixes  e.g. `assword`
     * @param  list<string>  $reservedNames  system names and mail aliases, e.g. `root`, `postmaster`
     */
    public function __construct(
        public int $maxLength = 16,
        public string $pattern = '/^[a-z][a-z0-9]*$/',
        public array $reservedPrefixes = [],
        public array $reservedSuffixes = [],
        public array $reservedNames = [],
    ) {}

    /**
     * Why the username is not allowed, or null when it is.
     */
    public function violation(string $username): ?string
    {
        return match (true) {
            strlen($username) > $this->maxLength => "must be at most {$this->maxLength} characters",
            preg_match($this->pattern, $username) !== 1 => 'contains characters the panel does not accept',
            in_array($username, $this->reservedNames, true) => 'is reserved on the panel',
            array_filter($this->reservedPrefixes, fn (string $prefix): bool => str_starts_with($username, $prefix)) !== [] => 'starts with a reserved prefix',
            array_filter($this->reservedSuffixes, fn (string $suffix): bool => str_ends_with($username, $suffix)) !== [] => 'ends with a reserved suffix',
            default => null,
        };
    }
}
