<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Services\Provisioning\ReportsUsage;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\UsageSnapshot;
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Automation task (SYNC): one usage snapshot per service per day from each server's driver.
 */
class SyncUsage
{
    public function __construct(private ProvisioningDriverRegistry $drivers, private ProvisioningSnapshots $snapshots, private Redactor $redactor) {}

    public function handle(CarbonImmutable $today): int
    {
        $count = 0;

        Server::query()->where('is_active', true)->orderBy('id')->each(function (Server $server) use ($today, &$count): void {
            if (! $this->drivers->has($server->driver)) {
                return;
            }

            $connection = null;

            try {
                $driver = $this->drivers->get($server->driver);

                if (! $driver instanceof ReportsUsage) {
                    return;
                }

                $rows = $driver->usage($connection = $this->snapshots->connection($server));
            } catch (Throwable $exception) {
                // Driver text can echo a request; report it masked, as the other Services callers do.
                report(new \RuntimeException($this->redactor->maskValues($exception->getMessage(), $connection?->secrets() ?? [])));

                return;
            }

            $services = $server->services()
                ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                ->whereIn('username', array_map('strval', array_keys($rows)))
                // Any snapshot on the run date (a plain range keeps (service_id, captured_at) usable; latestOfMany aggregated the whole table).
                ->whereDoesntHave('usageSnapshots', fn ($query) => $query->where('captured_at', '>=', $today->startOfDay())->where('captured_at', '<', $today->addDay()->startOfDay()))
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
