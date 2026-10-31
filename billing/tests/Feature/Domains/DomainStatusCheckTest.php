<?php

/*
| Database review 2026-10-31, M5 (#560): a domain holds only a DomainStatus on PostgreSQL.
*/

use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$pgsqlOnly = fn () => DB::connection()->getDriverName() !== 'pgsql';
$migrationPath = 'app/Modules/Domains/Database/Migrations/2026_11_05_500900_add_status_check_to_domains_table.php';

it('refuses a domain status outside DomainStatus', function (string $status) {
    $domain = Domain::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('domains')->where('id', $domain->id)->update(['status' => $status])))->toThrow(QueryException::class)
        ->and(DB::table('domains')->where('id', $domain->id)->value('status'))->toBe('active');
})->with([
    'TitleCase' => 'Active',
    'a registrar state stored as it came' => 'clientHold',
    'a WHMCS status' => 'Pending Transfer',
])->skip($pgsqlOnly, 'The check is PostgreSQL only (SQLite cannot add a CHECK to an existing table)');

test('the status check lists exactly the DomainStatus cases, so a new case comes with a migration', function () {
    $definition = (string) DB::table('pg_constraint')->where('conname', 'domains_status_check')->where('convalidated', true)->value(DB::raw('pg_get_constraintdef(oid)'));
    preg_match_all("/'([a-z_]+)'::/", $definition, $values);

    expect($values[1])->toEqualCanonicalizing(DomainStatus::values());
})->skip($pgsqlOnly, 'The check is PostgreSQL only');

test('rows that break the check stop the migration by id, and the check is not left behind', function () use ($migrationPath) {
    $migration = require base_path($migrationPath);
    $migration->down();
    $domain = Domain::factory()->create();
    DB::table('domains')->where('id', $domain->id)->update(['status' => 'transferred']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, "domains: 1 rows break domains_status_check (status IN ('pending', 'pending_registration', 'pending_transfer', 'active', 'grace', 'redemption', 'expired', 'transferred_away', 'cancelled', 'fraud')), ids {$domain->id}. The check was removed again")
        ->and(DB::table('pg_constraint')->where('conname', 'domains_status_check')->exists())->toBeFalse();

    DB::table('domains')->where('id', $domain->id)->update(['status' => DomainStatus::TransferredAway->value]);
    $migration->up();

    expect(DB::table('pg_constraint')->where('conname', 'domains_status_check')->value('convalidated'))->toBeTrue();
})->skip($pgsqlOnly, 'The check is PostgreSQL only');
