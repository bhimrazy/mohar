<?php

namespace App\Modules\Orders\Boot;

use App\Events\Billing\InvoicePaid;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainTransferred;
use App\Events\Services\ServiceActivated;
use App\Modules\Orders\Listeners\AcceptPaidOrder;
use App\Modules\Orders\Listeners\MergeCartOnLogin;
use App\Modules\Orders\Listeners\TrackItemActivation;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Event;

final class Listeners
{
    public function __invoke(Container $app): void
    {
        Event::listen(Login::class, MergeCartOnLogin::class);
        Event::listen(ServiceActivated::class, TrackItemActivation::class);
        Event::listen(DomainRegistered::class, TrackItemActivation::class);
        Event::listen(DomainTransferred::class, TrackItemActivation::class);
        Event::listen(InvoicePaid::class, AcceptPaidOrder::class);
    }
}
