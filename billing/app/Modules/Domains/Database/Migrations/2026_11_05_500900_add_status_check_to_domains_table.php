<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database review 2026-10-31, M5 (#560): a domain holds only a DomainStatus (domains.md §3). Registrar states are
 * mapped to these cases in the drivers and SyncDomain; they are never stored as they come.
 *
 * A new enum case needs a migration that replaces this check; DomainStatusCheckTest fails until then.
 *
 * PostgreSQL only, the same way as 2026_11_04_300830_add_check_constraints_to_billing_tables: the check is added
 * NOT VALID under one short lock, so new writes are checked at once; then it is validated, which scans without
 * blocking reads or writes. If existing rows break it, the check is dropped again and the migration stops naming the
 * rows. SQLite cannot add a CHECK to an existing table without a rebuild, so there this migration does nothing.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'domains_status_check';

    private const CONDITION = "status IN ('pending', 'pending_registration', 'pending_transfer', 'active', 'grace', 'redemption', 'expired', 'transferred_away', 'cancelled', 'fraud')";

    /** How many violating row ids the failure names. */
    private const NAMED_IDS = 20;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $validated = $this->validated();

        if ($validated === true) {
            return;
        }

        if ($validated === null) {
            // @compatible NOT VALID checks only new writes, and the previous release writes only DomainStatus values
            LockTimeout::run('domains', fn () => DB::statement('ALTER TABLE domains ADD CONSTRAINT '.self::NAME.' CHECK ('.self::CONDITION.') NOT VALID'));
        }

        // The NOT VALID check is already enforced on new writes, so this count is final.
        $violating = DB::table('domains')->whereRaw('NOT ('.self::CONDITION.')');
        $count = (clone $violating)->count();

        if ($count > 0) {
            // @compatible removes only the check this migration added and never validated: left behind NOT VALID it
            // would refuse every later update of these rows
            LockTimeout::run('domains', fn () => DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS '.self::NAME));

            throw new RuntimeException("domains: {$count} rows break ".self::NAME.' ('.self::CONDITION.'), ids '
                .$violating->orderBy('id')->limit(self::NAMED_IDS)->pluck('id')->implode(', ')
                .'. The check was removed again; correct the rows and run the migration again.');
        }

        LockTimeout::run('domains', fn () => DB::statement('ALTER TABLE domains VALIDATE CONSTRAINT '.self::NAME));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        LockTimeout::run('domains', fn () => DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS '.self::NAME));
    }

    /** Whether the check is on the table and validated; null when it is not there. */
    private function validated(): ?bool
    {
        $valid = DB::table('pg_constraint')->whereRaw('conrelid = to_regclass(?)', ['domains'])->where('contype', 'c')
            ->where('conname', self::NAME)->value('convalidated');

        return $valid === null ? null : (bool) $valid;
    }
};
