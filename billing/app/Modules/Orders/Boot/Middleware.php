<?php

namespace App\Modules\Orders\Boot;

use App\Modules\Orders\Http\Middleware\CaptureReferralAndPromo;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;

final class Middleware
{
    public function __invoke(Container $app): void
    {
        $app->make(HttpKernel::class)->appendMiddlewareToGroup('web', CaptureReferralAndPromo::class);
    }
}
