<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * credit_ledger.idempotency_key (#263): the once-only key of a posting Mohar makes itself (opening balances from an
 * import, later automation credits), so `gateway` and `external_ref` name provider movements only. Keys start with
 * the poster's name (`import:run:7:client:3`). Unique where set, built CONCURRENTLY on PostgreSQL.
 *
 * Existing opening-balance entries (gateway `import`) get their key copied: `import:` followed by their external_ref.
 * Their gateway and external_ref stay as they are (expand only: the previous release still reads them).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const BATCH = 1000;

    public function up(): void
    {
        if (! Schema::hasColumn('credit_ledger', 'idempotency_key')) {
            Schema::table('credit_ledger', fn (Blueprint $table) => $table->string('idempotency_key', 191)->nullable());
        }

        $this->backfill();

        OnlineIndex::create('credit_ledger', ['idempotency_key'], 'credit_ledger_idempotency_key_unique', unique: true, where: 'idempotency_key is not null');
    }

    /**
     * Repeatable: only rows without a key are touched. The partial unique index on (gateway, external_ref) serves the
     * lookup; each batch is its own short statement, so the table is never locked for the whole copy.
     */
    public function backfill(): void
    {
        DB::table('credit_ledger')->where('gateway', 'import')->whereNotNull('external_ref')->whereNull('idempotency_key')
            ->select('id')->chunkById(self::BATCH, function ($rows): void {
                DB::table('credit_ledger')->whereIn('id', $rows->pluck('id')->all())->whereNull('idempotency_key')
                    ->update(['idempotency_key' => DB::raw("'import:' || external_ref")]);
            });
    }

    public function down(): void
    {
        OnlineIndex::drop('credit_ledger', 'credit_ledger_idempotency_key_unique');

        if (Schema::hasColumn('credit_ledger', 'idempotency_key')) {
            Schema::table('credit_ledger', fn (Blueprint $table) => $table->dropColumn('idempotency_key'));
        }
    }
};
