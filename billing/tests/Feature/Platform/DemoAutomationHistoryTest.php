<?php

use App\Modules\Core\Database\Seeders\CoreSeeder;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Platform\Database\Seeders\PlatformSeeder;
use Illuminate\Support\Carbon;

it('records the scheduler tick that ended the seeded automation history', function () {
    $this->seed(CoreSeeder::class);
    $this->seed(PlatformSeeder::class);

    $lastRun = AutomationRun::query()->max('created_at');
    $heartbeat = app(AutomationStatus::class)->heartbeatAt();

    expect($lastRun)->not->toBeNull()
        ->and($heartbeat)->not->toBeNull()
        ->and($heartbeat->greaterThanOrEqualTo(Carbon::parse($lastRun)))->toBeTrue()
        ->and($heartbeat->isFuture())->toBeFalse();
});
