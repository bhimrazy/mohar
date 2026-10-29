<?php

namespace Tests\Fixtures\KernelApi\Enums;

/**
 * @open
 */
enum Size: string
{
    case Small = 'sm';
    case Large = 'lg';

    public function label(): string
    {
        return ucfirst($this->name);
    }
}
