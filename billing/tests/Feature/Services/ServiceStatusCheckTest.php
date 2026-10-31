<?php

/*
| Database review 2026-10-31, M5 (#559): services and service addons hold only a ServiceStatus on PostgreSQL.
*/

use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$pgsqlOnly = fn () => DB::connection()->getDriverName() !== 'pgsql';
$migrationPath = 'app/Modules/Services/Database/Migrations/2026_11_05_400900_add_status_checks_to_services_tables.php';

it('refuses a service or addon status outside ServiceStatus', function (string $table, Closure $row, string $status) {
    $id = $row()->id;

    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['status' => $status])))->toThrow(QueryException::class)
        ->and(DB::table($table)->where('id', $id)->value('status'))->toBe('active');
})->with([
    'a TitleCase service status' => ['services', fn () => Service::factory()->create(), 'Active'],
    'an unknown service status' => ['services', fn () => Service::factory()->create(), 'deleted'],
    'an unknown addon status' => ['service_addons', fn () => ServiceAddon::factory()->create(), 'expired'],
])->skip($pgsqlOnly, 'The checks are PostgreSQL only (SQLite cannot add a CHECK to an existing table)');

test('each status check lists exactly the ServiceStatus cases, so a new case comes with a migration', function (string $constraint) {
    $definition = (string) DB::table('pg_constraint')->where('conname', $constraint)->where('convalidated', true)->value(DB::raw('pg_get_constraintdef(oid)'));
    preg_match_all("/'([a-z_]+)'::/", $definition, $values);

    expect($values[1])->toEqualCanonicalizing(ServiceStatus::values());
})->with(['services_status_check', 'service_addons_status_check'])->skip($pgsqlOnly, 'The checks are PostgreSQL only');

test('rows that break a check stop the migration by id, and the check is not left half applied', function () use ($migrationPath) {
    $migration = require base_path($migrationPath);
    $migration->down();
    $services = Service::factory()->count(2)->create();
    DB::table('services')->whereIn('id', $services->modelKeys())->update(['status' => 'Suspended']);
    Service::factory()->create();

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, "services: 2 rows break services_status_check (status IN ('pending', 'active', 'suspended', 'terminated', 'cancelled', 'fraud', 'completed')), ids {$services[0]->id}, {$services[1]->id}. The checks of this table not yet validated were removed again")
        ->and(DB::table('pg_constraint')->where('conname', 'services_status_check')->exists())->toBeFalse();

    DB::table('services')->whereIn('id', $services->modelKeys())->update(['status' => ServiceStatus::Suspended->value]);
    $migration->up();

    expect(DB::table('pg_constraint')->whereIn('conname', ['services_status_check', 'service_addons_status_check'])->pluck('convalidated', 'conname')->all())
        ->toEqual(['services_status_check' => true, 'service_addons_status_check' => true]);
})->skip($pgsqlOnly, 'The checks are PostgreSQL only');

test('each status check holds no value outside ServiceStatus, whatever its spelling', function (string $constraint) {
    $definition = (string) DB::table('pg_constraint')->where('conname', $constraint)->value(DB::raw('pg_get_constraintdef(oid)'));
    preg_match_all("/'([^']*)'::/", $definition, $values);

    expect($values[1])->toEqualCanonicalizing(ServiceStatus::values());
})->with(['services_status_check', 'service_addons_status_check'])->skip($pgsqlOnly, 'The checks are PostgreSQL only');

test('a table whose rows pass keeps its validated check when a later table fails, and the re-run finishes the rest', function () use ($migrationPath) {
    $migration = require base_path($migrationPath);
    $migration->down();
    $addon = ServiceAddon::factory()->create();
    DB::table('service_addons')->where('id', $addon->id)->update(['status' => 'Active']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'service_addons: 1 rows break service_addons_status_check')
        ->and(DB::table('pg_constraint')->whereIn('conname', ['services_status_check', 'service_addons_status_check'])->pluck('convalidated', 'conname')->all())
        ->toEqual(['services_status_check' => true]);

    DB::table('service_addons')->where('id', $addon->id)->update(['status' => ServiceStatus::Active->value]);
    $migration->up();

    expect(DB::table('pg_constraint')->whereIn('conname', ['services_status_check', 'service_addons_status_check'])->pluck('convalidated', 'conname')->all())
        ->toEqual(['services_status_check' => true, 'service_addons_status_check' => true]);
})->skip($pgsqlOnly, 'The checks are PostgreSQL only');
