<?php

namespace Tests\Fixtures\KernelApi\Support;

use DateTimeZone;
use Tests\Fixtures\KernelApi\Contracts\Widget;
use Tests\Fixtures\KernelApi\Enums\Size;

/**
 * @api
 *
 * @template TItem of Widget
 */
class Box implements Widget
{
    use Stamps;

    final public const string NAME = 'box';

    protected const LIMIT = 3;

    private const SECRET = 'no';

    /** @var list<string> */
    public array $labels = ['new'];

    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(public readonly int $width, protected array $tags = ['a'], public DateTimeZone $zone = new DateTimeZone('UTC')) {}

    /**
     * @return non-empty-string
     */
    public function render(Size $size = Size::Small, ?string $title = null, string ...$classes): string
    {
        return '-'.self::SECRET;
    }

    /**
     * @deprecated since 0.1.0; use render()
     */
    public static function draw(int &$count): void {}

    /**
     * @param  callable(TItem): bool  $filter
     * @return list<TItem>
     */
    protected function filter(callable $filter): array
    {
        return [];
    }

    /**
     * @internal
     */
    public function reset(): void {}

    private function internal(): void {}
}
