<?php

namespace Tests\Fixtures\KernelApi\Support;

use Tests\Fixtures\KernelApi\Contracts\Widget;
use Tests\Fixtures\KernelApi\Enums\Size;

/**
 * @api
 */
final class Box implements Widget
{
    use Stamps;

    private const SECRET = 'no';

    public function __construct(public readonly int $width, protected array $tags = ['a']) {}

    public function render(Size $size = Size::Small, ?string $title = null, string ...$classes): string
    {
        return '';
    }

    /**
     * @deprecated since 0.1.0; use render()
     */
    public static function draw(int &$count): void {}

    protected function hidden(): void {}

    private function internal(): void {}
}
