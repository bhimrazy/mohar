<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database audit 2026-10-18 (docs/database/audit-2026-10-18.md).
 * - Foreign keys and the per-domain registrar log were unindexed (PostgreSQL does not index foreign keys).
 * - unique(tld_id, currency, client_group_id, years) never applies to the default price list, because
 *   client_group_id is NULL there and NULLs are distinct: two first saves of a TLD's prices could both insert.
 *   A partial unique index covers the NULL group.
 * Built CONCURRENTLY on PostgreSQL (no write lock) and skipped when an index of the same name exists.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<array{0: string, 1: list<string>}> */
    private const INDEXES = [
        ['domains', ['tld_id']],
        ['tld_prices', ['client_group_id']],
        ['domain_reminders', ['client_id']],
        ['registrar_command_log', ['domain_id', 'id']],
        ['registrar_command_log', ['staff_id']],
    ];

    public function up(): void
    {
        // Data check first, so a deploy with bad data fails before the index builds.
        $duplicates = DB::table('tld_prices')->whereNull('client_group_id')
            ->select('tld_id', 'currency', 'years')->groupBy('tld_id', 'currency', 'years')->havingRaw('count(*) > 1')->count();

        if ($duplicates > 0) {
            throw new RuntimeException("{$duplicates} default TLD prices are stored twice (same TLD, currency and years). Find them with: "
                .'select tld_id, currency, years, array_agg(id) from tld_prices where client_group_id is null group by 1, 2, 3 having count(*) > 1; '
                .'remove the extra rows, then run the migration again.');
        }

        foreach (self::INDEXES as [$table, $columns]) {
            OnlineIndex::create($table, $columns);
        }

        OnlineIndex::create('tld_prices', ['tld_id', 'currency', 'years'], 'tld_prices_default_price_unique', unique: true, where: 'client_group_id is null');
    }

    public function down(): void
    {
        OnlineIndex::drop('tld_prices', 'tld_prices_default_price_unique');

        foreach (array_reverse(self::INDEXES) as [$table, $columns]) {
            OnlineIndex::drop($table, $columns);
        }
    }
};
