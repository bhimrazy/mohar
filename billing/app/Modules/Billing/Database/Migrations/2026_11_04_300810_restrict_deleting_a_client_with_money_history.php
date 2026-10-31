<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database review 2026-10-31, H1: a client's credit ledger, payment attempts and payment batches are money history, so
 * deleting the client row is refused while any exist instead of taking them along (ON DELETE CASCADE becomes
 * RESTRICT). `pay_methods` keeps its cascade: a stored token is not history.
 *
 * PostgreSQL: the key is swapped NOT VALID under a short lock, then validated, which scans the table without blocking
 * reads or writes. Every row already satisfies it (the old key was the same reference). SQLite rebuilds the table.
 *
 * @compatible no code path deletes a client (CloseClient and MergeClients close it), so the previous release never
 * meets the stricter key; deploy:check-migrations reads a re-added key with RESTRICT as a tightening.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLES = ['credit_ledger', 'payment_attempts', 'payment_batches'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            LockTimeout::run($table, fn () => $this->rekey($table, 'restrict'));
            $this->validate($table);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            LockTimeout::run($table, fn () => $this->rekey($table, 'cascade'));
            $this->validate($table);
        }
    }

    private function rekey(string $table, string $onDelete): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // @compatible the same reference with a stricter action; see the class comment
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_client_id_foreign, ADD CONSTRAINT {$table}_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE {$onDelete} NOT VALID");

            return;
        }

        // Laravel changes a SQLite foreign key by rebuilding the table and recreates its indexes from their columns
        // only, which loses a partial index's WHERE; those are put back as they were.
        $indexes = fn () => DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', $table)->whereNotNull('sql')->pluck('sql', 'name');
        $before = $indexes();
        Schema::table($table, function (Blueprint $blueprint) use ($onDelete): void {
            $blueprint->dropForeign(['client_id']);
            $blueprint->foreign('client_id')->references('id')->on('clients')->onDelete($onDelete);
        });
        $after = $indexes();

        foreach ($before as $name => $sql) {
            if (($after[$name] ?? null) !== $sql) {
                // @compatible SQLite only: the rebuild's copy of the index is replaced by the original definition
                DB::statement('drop index if exists "'.$name.'"');
                DB::statement($sql);
            }
        }
    }

    private function validate(string $table): void
    {
        if (DB::getDriverName() === 'pgsql') {
            LockTimeout::run($table, fn () => DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$table}_client_id_foreign"));
        }
    }
};
