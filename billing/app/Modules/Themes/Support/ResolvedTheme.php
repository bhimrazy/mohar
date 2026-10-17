<?php

namespace App\Modules\Themes\Support;

/**
 * A theme ready to render: a registry preset or a saved custom theme.
 */
final readonly class ResolvedTheme
{
    /**
     * @param  array{shared: array<string, string|int>, light: array<string, string>, dark: array<string, string>}  $tokens
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $tokens,
        public string $version,
        public bool $custom = false,
        public string $description = '',
    ) {}
}
