<?php

namespace Tests\Fixtures\KernelApi\Contracts;

use Tests\Fixtures\KernelApi\Enums\Size;

interface Widget
{
    public const KIND = 'widget';

    public function render(Size $size = Size::Small, ?string $title = null, string ...$classes): string;
}
