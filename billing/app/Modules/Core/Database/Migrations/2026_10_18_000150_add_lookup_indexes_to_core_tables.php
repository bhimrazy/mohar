<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database audit 2026-10-18 (docs/database/audit-2026-10-18.md). PostgreSQL does not index foreign keys, so
 * deleting a user or client scanned whole tables, and lookups by user, contact owner, group or sign-up date were
 * sequential scans. Inbound mail matches senders with lower(email), which needs an expression index. At most
 * one currency may be the default. Indexes are built CONCURRENTLY on PostgreSQL (no write lock).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<array{0: string, 1: list<string>}> */
    private const INDEXES = [
        ['client_user', ['user_id']],
        ['contacts', ['client_id']],
        ['clients', ['client_group_id']],
        ['clients', ['created_at']],
        ['users', ['role_id']],
        ['activity_log', ['user_id', 'id']],
    ];

    private const EMAIL_TABLES = ['clients', 'contacts', 'users'];

    public function up(): void
    {
        // Data check first, so a deploy with bad data fails before minutes of index builds.
        $defaults = DB::table('currencies')->where('is_default', true)->count();

        if ($defaults > 1) {
            throw new RuntimeException("{$defaults} currencies are marked as the default (find them: select id, code from currencies where is_default). Keep exactly one (Settings → Currencies), then run the migration again.");
        }

        foreach (self::INDEXES as [$table, $columns]) {
            OnlineIndex::create($table, $columns);
        }

        foreach (self::EMAIL_TABLES as $table) {
            OnlineIndex::create($table, [], "{$table}_email_lower_index", expression: 'lower(email)');
        }

        OnlineIndex::create('currencies', ['is_default'], 'currencies_single_default_unique', unique: true, where: 'is_default = true');
    }

    public function down(): void
    {
        OnlineIndex::drop('currencies', 'currencies_single_default_unique');

        foreach (self::EMAIL_TABLES as $table) {
            OnlineIndex::drop($table, "{$table}_email_lower_index");
        }

        foreach (self::INDEXES as [$table, $columns]) {
            OnlineIndex::drop($table, $columns);
        }
    }
};
