<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Drivers\Provisioning\DriverMetadata;
use App\Modules\Services\Drivers\Provisioning\DriverRegistry;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\UsageSnapshot;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Automation task (SYNC): one usage snapshot per service per day from each server's driver.
 */
class SyncUsage
{
    public function __construct(private DriverRegistry $drivers) {}

    public function handle(CarbonImmutable $today): int
    {
        $count = 0;

        Server::query()->where('is_active', true)->orderBy('id')->each(function (Server $server) use ($today, &$count): void {
            if (! $this->drivers->has($server->driver)) {
                return;
            }

            $driver = $this->drivers->get($server->driver);

            if (! $driver->metadata()->supports(DriverMetadata::USAGE)) {
                return;
            }

            try {
                $rows = $driver->usage($server);
            } catch (Throwable $exception) {
                report($exception);

                return;
            }

            $services = $server->services()
                ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                ->whereIn('username', array_map('strval', array_keys($rows)))
                ->whereDoesntHave('latestUsage', fn ($query) => $query->whereDate('captured_at', $today))
                ->get(['id', 'username']);

            foreach ($services as $service) {
                $row = $rows[(string) $service->username];
                UsageSnapshot::query()->create([
                    'service_id' => $service->id,
                    'disk_used_mb' => $row->diskUsedMb,
                    'disk_limit_mb' => $row->diskLimitMb,
                    'bw_used_mb' => $row->bandwidthUsedMb,
                    'bw_limit_mb' => $row->bandwidthLimitMb,
                    'captured_at' => $today->setTimeFrom(CarbonImmutable::now()),
                ]);
                $count++;
            }
        });

        return $count;
    }
}
