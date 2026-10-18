<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Database audit 2026-10-18 (docs/database/audit-2026-10-18.md): a client's services, a server's accounts,
 * a service's addons, changes, cancellation requests, module log and usage history were sequential scans
 * (PostgreSQL does not index foreign keys). The usage index also serves latestOfMany('captured_at').
 * Built CONCURRENTLY on PostgreSQL (no write lock) and skipped when an index of the same name exists.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<array{0: string, 1: list<string>}> */
    private const INDEXES = [
        ['services', ['client_id', 'status']],
        ['services', ['server_id']],
        ['server_group_server', ['server_id']],
        ['service_addons', ['service_id']],
        ['service_changes', ['service_id']],
        ['cancellation_requests', ['service_id']],
        ['cancellation_requests', ['requested_by_user_id']],
        ['module_commands', ['service_id', 'id']],
        ['module_commands', ['service_addon_id']],
        ['module_commands', ['server_id']],
        ['module_queue', ['resolved_by']],
        ['service_usage_snapshots', ['service_id', 'captured_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $columns]) {
            OnlineIndex::create($table, $columns);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEXES) as [$table, $columns]) {
            OnlineIndex::drop($table, $columns);
        }
    }
};
