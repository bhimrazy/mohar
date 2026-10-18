<?php

namespace App\Support\Themes;

/**
 * A built-in theme registered by a module or theme pack. Tokens follow ThemeSchema.
 */
final readonly class ThemePreset
{
    /**
     * @param  array{shared: array<string, string|int>, light: array<string, string>, dark: array<string, string>}  $tokens
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $tokens,
        public string $description = '',
        public string $version = '1',
    ) {}

    /**
     * Build from a theme document ({"schema": "billing-theme", "version": 1, "key", "name", "tokens"}).
     *
     * @param  array<string, mixed>  $document
     */
    public static function fromDocument(array $document, string $version = '1'): self
    {
        /** @var array{shared: array<string, string|int>, light: array<string, string>, dark: array<string, string>} $tokens */
        $tokens = $document['tokens'];

        return new self(
            key: (string) $document['key'],
            name: (string) $document['name'],
            tokens: $tokens,
            description: (string) ($document['description'] ?? ''),
            version: $version,
        );
    }
}
