<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Database review 2026-10-31, M3: the tax rule an invoice was calculated with is the input of its Nepal VAT
 * classification (ADR 0024 Amendment #432), so deleting a rule an invoice names is refused (RESTRICT).
 *
 * Invoices whose rule was deleted before this key existed are logged with their ids and left as they are: nulling
 * the id would switch them to name-and-rate matching, which is a classification change staff must decide on. On
 * PostgreSQL the key is added NOT VALID first, which checks every new write and every rule delete from then on, so the
 * orphans counted after it are final; it is validated when there are none, and otherwise stays NOT VALID until staff
 * fix the ids and run the VALIDATE in the log. Adding the keys takes SHARE ROW EXCLUSIVE on `tax_rules` (ACCESS
 * EXCLUSIVE on a re-run, whose DROP removes the key's triggers there). SQLite rebuilds the table and does not check
 * the rows it copies.
 *
 * No index on the two columns: a rule is deleted rarely, and the scan that the delete's check makes is cheaper than
 * two more indexes on every invoice write.
 *
 * @compatible the previous release writes only ids of existing rules; from this migration until traffic moves (and
 * after a rollback) its rule delete gets a database refusal, a 500 instead of a message, for a rule an invoice uses,
 * and nothing is deleted
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const COLUMNS = ['tax1_rule_id', 'tax2_rule_id'];

    /** How many orphaned invoice ids the log names per column. */
    private const LOGGED_IDS = 100;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->logOrphans();
            LockTimeout::run('invoices', fn () => $this->rebuildOnSqlite(add: true));

            return;
        }

        // @compatible a new key, NOT VALID; the previous release writes only ids of existing rules (the DROP makes a rerun safe)
        LockTimeout::run('invoices', fn () => DB::statement('ALTER TABLE invoices '.implode(', ', array_map(
            fn (string $column): string => "DROP CONSTRAINT IF EXISTS invoices_{$column}_foreign, ADD CONSTRAINT invoices_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES tax_rules (id) ON DELETE RESTRICT NOT VALID",
            self::COLUMNS,
        ))), alsoLocks: ['tax_rules']);
        $orphans = $this->logOrphans();

        foreach (self::COLUMNS as $column) {
            if ($orphans[$column] === 0) {
                LockTimeout::run('invoices', fn () => DB::statement("ALTER TABLE invoices VALIDATE CONSTRAINT invoices_{$column}_foreign"));
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            LockTimeout::run('invoices', fn () => $this->rebuildOnSqlite(add: false));

            return;
        }

        LockTimeout::run('invoices', fn () => DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_tax1_rule_id_foreign, DROP CONSTRAINT IF EXISTS invoices_tax2_rule_id_foreign'), alsoLocks: ['tax_rules']);
    }

    /**
     * @return array<string, int> orphaned invoices per column
     */
    private function logOrphans(): array
    {
        $counts = [];

        foreach (self::COLUMNS as $column) {
            $orphans = DB::table('invoices')->whereNotNull($column)
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('tax_rules')->whereColumn('tax_rules.id', "invoices.{$column}"));
            $counts[$column] = (clone $orphans)->count();

            if ($counts[$column] > 0) {
                Log::warning("Invoices name a deleted tax rule in {$column}; they were left unchanged and the foreign key is not validated", [
                    'column' => $column,
                    'count' => $counts[$column],
                    'invoice_ids' => $orphans->orderBy('id')->limit(self::LOGGED_IDS)->pluck('id')->all(),
                    'then_run' => "ALTER TABLE invoices VALIDATE CONSTRAINT invoices_{$column}_foreign",
                ]);
            }
        }

        return $counts;
    }

    /**
     * Laravel changes a SQLite foreign key by rebuilding the table and recreates its indexes from their columns only,
     * which loses a partial index's WHERE; those are put back as they were.
     */
    private function rebuildOnSqlite(bool $add): void
    {
        $indexes = fn () => DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'invoices')->whereNotNull('sql')->pluck('sql', 'name');
        $before = $indexes();
        // @compatible adds the new keys going up and drops them going down; nothing is re-added with a stricter action
        Schema::table('invoices', function (Blueprint $table) use ($add): void {
            foreach (self::COLUMNS as $column) {
                $add ? $table->foreign($column)->references('id')->on('tax_rules')->restrictOnDelete() : $table->dropForeign([$column]);
            }
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
};
