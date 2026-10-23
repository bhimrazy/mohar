<?php

namespace App\Modules\Services\Services;

use App\Contracts\Core\CompanyClock;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use Carbon\CarbonImmutable;

/**
 * Status changes of service addons. Addons are provisioned manually (no driver call), so their
 * transitions are local, but they follow the same state machine as services.
 */
class AddonLifecycle
{
    /** Suspension reason used when an addon is suspended because its parent was. */
    public const PARENT_REASON = 'parent_suspended';

    public function __construct(private CompanyClock $clock) {}

    public function transition(ServiceAddon $addon, ServiceStatus $target, ?string $reason = null): void
    {
        $addon->status->assertCanTransitionTo($target);

        $attributes = ['status' => $target];

        if ($target === ServiceStatus::Active) {
            $attributes += ['activated_at' => $addon->activated_at ?? CarbonImmutable::now(), 'suspended_at' => null, 'suspension_reason' => null];
        }

        if ($target === ServiceStatus::Suspended) {
            $attributes += ['suspended_at' => CarbonImmutable::now(), 'suspension_reason' => $reason];
        }

        if (in_array($target, [ServiceStatus::Terminated, ServiceStatus::Cancelled], true)) {
            $attributes += ['termination_date' => $this->clock->todayDate()];
        }

        $addon->update($attributes);
    }

    public function cascadeSuspend(Service $service): void
    {
        $service->addons()->where('status', ServiceStatus::Active->value)->get()
            ->each(fn (ServiceAddon $addon) => $this->transition($addon, ServiceStatus::Suspended, self::PARENT_REASON));
    }

    public function cascadeUnsuspend(Service $service): void
    {
        $service->addons()->where('status', ServiceStatus::Suspended->value)->where('suspension_reason', self::PARENT_REASON)->get()
            ->each(fn (ServiceAddon $addon) => $this->transition($addon, ServiceStatus::Active));
    }

    /**
     * Parent terminated or cancelled: every live addon is cancelled with today's termination date.
     */
    public function cancelAll(Service $service, CarbonImmutable $today): void
    {
        $service->addons()->whereIn('status', ServiceStatus::occupyingValues())->get()
            ->each(fn (ServiceAddon $addon) => $addon->update(['status' => ServiceStatus::Cancelled, 'termination_date' => $today]));
    }
}
