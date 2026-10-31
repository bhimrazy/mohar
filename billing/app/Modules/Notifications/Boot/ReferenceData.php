<?php

namespace App\Modules\Notifications\Boot;

use App\Modules\Notifications\Install\DefaultTemplates;
use App\Support\Install\ReferenceDataRegistry;
use Illuminate\Contracts\Container\Container;

final class ReferenceData
{
    public function __invoke(Container $app): void
    {
        $app->make(ReferenceDataRegistry::class)->register(DefaultTemplates::KEY, DefaultTemplates::class, order: 100);
    }
}
