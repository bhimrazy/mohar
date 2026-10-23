<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Domains\DomainFixtures;

require_once __DIR__.'/../Billing/Support/race.php';

/**
 * Two staff link one order to two rows of the same name (an Active and an Expired record of x.com) in real concurrent
 * transactions (ADR 0021 §4). Both rows are locked in id order before the "no other row holds it" check, so the
 * second linker waits, sees the first one's id and is refused. Without that lock both pass the check and both commit.
 * Skipped on SQLite, where one database file cannot host the race.
 */
describe('linking one order to two same-name rows at once (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('exactly one of two concurrent links wins and the other is refused', function () {
        $this->useSeededDatabase('link365_race', function (): void {
            $tld = DomainFixtures::com();
            $active = Domain::factory()->create(['tld_id' => $tld->id, 'registrar_key' => 'fake', 'status' => DomainStatus::Active, 'sld' => 'twin365', 'name' => 'twin365.com', 'name_unicode' => 'twin365.com']);
            Domain::factory()->create(['tld_id' => $tld->id, 'registrar_key' => 'fake', 'status' => DomainStatus::Expired, 'sld' => $active->sld, 'name' => $active->name, 'name_unicode' => $active->name]);
        });
        [$a, $b] = Domain::query()->where('name', 'twin365.com')->orderBy('id')->pluck('id')->all();
        $database = DB::connection()->getDatabaseName();

        // Each worker waits 1 s after its check and before its write, so without the same-name row lock both would pass the check.
        $results = runRaceWorkers([
            'one' => ['link', $database, (string) $a, '80000365', '1000'],
            'two' => ['link', $database, (string) $b, '80000365', '1000'],
        ], 60.0, __DIR__.'/Support/link-race-worker.php');

        $holders = Domain::query()->where('name', 'twin365.com')->get()->filter(fn (Domain $row): bool => ($row->registrar_meta['remote']['order_id'] ?? null) === 80000365);
        $outcomes = collect($results)->pluck('outcome')->sort()->values()->all();
        $refused = collect($results)->firstWhere('outcome', 'refused');

        expect($outcomes)->toBe(['linked', 'refused'])
            ->and($refused['message'])->toContain('is already linked to twin365.com')
            ->and($holders)->toHaveCount(1)
            ->and(ActivityLog::query()->where('event', 'domain.order_linked')->count())->toBe(1);

        DB::table('activity_log')->delete();
        DB::table('registrar_command_log')->delete();
        DB::table('domains')->delete();
    });
});
