<?php

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\UsageSnapshot;
use App\Support\CalendarDate;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Services\ServicesTestKit;

beforeEach(function () {
    ServicesTestKit::bind();
    inCompanyTimezone('Asia/Kathmandu');
    [$this->start] = app(CompanyClock::class)->dayBounds(CalendarDate::parse('2026-10-24')->addDays(-395));
});

function usageSnapshotAt(Service $service, DateTimeInterface $at, int $diskMb): void
{
    UsageSnapshot::query()->create(['service_id' => $service->id, 'disk_used_mb' => $diskMb, 'bw_used_mb' => 0, 'captured_at' => $at]);
}

test('usage history keeps 395 days, and each service keeps its latest snapshot however old', function () {
    $active = Service::factory()->create();
    usageSnapshotAt($active, $this->start->subDays(10), 1);
    usageSnapshotAt($active, $this->start->subSecond(), 2);
    usageSnapshotAt($active, $this->start, 3);
    usageSnapshotAt($active, now(), 4);
    // Usage sync stopped long ago (a terminated service): its last snapshot is what the service page shows.
    $stale = Service::factory()->create();
    usageSnapshotAt($stale, $this->start->subDays(300), 10);
    usageSnapshotAt($stale, $this->start->subDays(200), 11);

    $this->artisan('automation:run', ['--only' => 'services.usage_prune', '--date' => '2026-10-24'])->assertSuccessful();

    expect(UsageSnapshot::query()->where('service_id', $active->id)->orderBy('id')->pluck('disk_used_mb')->all())->toBe([3, 4])
        ->and(UsageSnapshot::query()->where('service_id', $stale->id)->pluck('disk_used_mb')->all())->toBe([11]);
});

test('a stored usage retention below 90 days is read as 90', function () {
    app(Settings::class)->set('services.usage.retention_days', 1);
    $service = Service::factory()->create();
    [$start] = app(CompanyClock::class)->dayBounds(CalendarDate::parse('2026-10-24')->addDays(-90));
    usageSnapshotAt($service, $start->subSecond(), 1);
    usageSnapshotAt($service, $start, 2);
    usageSnapshotAt($service, now(), 3);

    $this->artisan('automation:run', ['--only' => 'services.usage_prune', '--date' => '2026-10-24'])->assertSuccessful();

    expect(UsageSnapshot::query()->orderBy('id')->pluck('disk_used_mb')->all())->toBe([2, 3]);
});

test('the module log page runs no count over the log', function () {
    $service = Service::factory()->create();
    foreach (range(1, 26) as $i) {
        ModuleCommand::query()->create(['service_id' => $service->id, 'driver' => 'none', 'command' => 'create', 'initiator' => 'system', 'success' => true]);
    }
    actingAsStaff(['services.module_log.view']);
    DB::enableQueryLog();

    $this->get(route('admin.services.module-log.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('log.data', 25)
            ->missing('log.meta.total')
            ->where('log.links.next', fn ($url) => is_string($url)));

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains(strtolower($sql), 'count(') && str_contains($sql, 'module_commands')))->toBeEmpty();
});
