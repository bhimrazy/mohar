<?php

namespace App\Modules\Notifications\Rendering;

/**
 * One open block while TemplateEngine parses: the nodes collected so far and the block they belong to.
 *
 * @internal
 */
final class ParseFrame
{
    /** @var list<array<string, mixed>> */
    public array $nodes = [];

    /**
     * @param  array<string, mixed>|null  $block  the open if/for node (null for the document root)
     * @param  'then'|'else'|'body'  $branch
     */
    public function __construct(
        public ?array $block = null,
        public string $branch = 'then',
        public int $line = 0,
    ) {}

    public function type(): ?string
    {
        $type = $this->block['type'] ?? null;

        return is_string($type) ? $type : null;
    }
}
