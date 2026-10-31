<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database review 2026-10-31, M5 (#559): services and service addons hold only a ServiceStatus.
 *
 * A new enum case needs a migration that replaces these checks; ServiceStatusCheckTest fails until then.
 *
 * PostgreSQL only, the same way as 2026_11_04_300830_add_check_constraints_to_billing_tables: each check is added
 * NOT VALID under one short lock, so new writes are checked at once; then it is validated, which scans without
 * blocking reads or writes. If existing rows break it, the check is dropped again and the migration stops naming the
 * rows. A table done before stays checked. SQLite cannot add a CHECK to an existing table without a rebuild, so there
 * this migration does nothing.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, array<string, string>> table => constraint => condition */
    private const CHECKS = [
        'services' => [
            'services_status_check' => "status IN ('pending', 'active', 'suspended', 'terminated', 'cancelled', 'fraud', 'completed')",
        ],
        'service_addons' => [
            'service_addons_status_check' => "status IN ('pending', 'active', 'suspended', 'terminated', 'cancelled', 'fraud', 'completed')",
        ],
    ];

    /** How many violating row ids the failure names. */
    private const NAMED_IDS = 20;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CHECKS as $table => $checks) {
            $missing = array_diff_key($checks, $this->existing($table));

            if ($missing !== []) {
                // @compatible NOT VALID checks only new writes, and the previous release writes only ServiceStatus values
                LockTimeout::run($table, fn () => DB::statement("ALTER TABLE {$table} ".implode(', ', array_map(
                    fn (string $name, string $condition): string => "ADD CONSTRAINT {$name} CHECK ({$condition}) NOT VALID",
                    array_keys($missing),
                    $missing,
                ))));
            }

            // NOT VALID checks are already enforced on new writes, so these counts are final.
            $pending = array_keys(array_filter($this->existing($table), fn (bool $valid): bool => ! $valid));
            $broken = [];

            foreach ($pending as $name) {
                $violating = DB::table($table)->whereRaw('NOT ('.$checks[$name].')');
                $count = (clone $violating)->count();

                if ($count > 0) {
                    $broken[] = "{$count} rows break {$name} ({$checks[$name]}), ids ".$violating->orderBy('id')->limit(self::NAMED_IDS)->pluck('id')->implode(', ');
                }
            }

            if ($broken !== []) {
                // @compatible removes only checks this migration added and never validated: one left behind NOT VALID
                // would refuse every later update of the rows it found
                LockTimeout::run($table, fn () => DB::statement("ALTER TABLE {$table} ".implode(', ', array_map(
                    fn (string $name): string => "DROP CONSTRAINT IF EXISTS {$name}",
                    $pending,
                ))));

                throw new RuntimeException("{$table}: ".implode('; ', $broken).'. The checks of this table not yet validated were removed again; correct the rows and run the migration again.');
            }

            foreach ($pending as $name) {
                LockTimeout::run($table, fn () => DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}"));
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CHECKS as $table => $checks) {
            LockTimeout::run($table, fn () => DB::statement("ALTER TABLE {$table} ".implode(', ', array_map(
                fn (string $name): string => "DROP CONSTRAINT IF EXISTS {$name}",
                array_keys($checks),
            ))));
        }
    }

    /**
     * @return array<string, bool> constraint name => validated, for this migration's checks already on the table
     */
    private function existing(string $table): array
    {
        return DB::table('pg_constraint')->whereRaw('conrelid = to_regclass(?)', [$table])->where('contype', 'c')
            ->whereIn('conname', array_keys(self::CHECKS[$table]))->pluck('convalidated', 'conname')->map(fn ($valid): bool => (bool) $valid)->all();
    }
};
