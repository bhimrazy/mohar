<?php

namespace Tests\Fixtures\KernelApi\Support;

trait Stamps
{
    public ?string $stamp = null;

    public function stamp(): string
    {
        return (string) $this->stamp;
    }
}
