<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Database audit 2026-10-18 (docs/database/audit-2026-10-18.md).
 * - Foreign keys that deletes null or cascade through were unindexed (PostgreSQL does not index foreign keys).
 * - Three single-column ticket indexes duplicate the leading column of a composite index
 *   ((client_id|department_id|status), ...): every ticket write paid for them and no plan uses them.
 * Built and dropped CONCURRENTLY on PostgreSQL (no write lock).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<array{0: string, 1: list<string>}> */
    private const INDEXES = [
        ['support_tickets', ['user_id']],
        ['support_tickets', ['contact_id']],
        ['support_ticket_messages', ['contact_id']],
        ['support_ticket_messages', ['edited_by']],
        ['support_ticket_events', ['user_id']],
        ['support_canned_replies', ['category_id']],
        ['support_canned_reply_categories', ['parent_id']],
        ['support_kb_categories', ['parent_id']],
        ['support_network_incident_updates', ['incident_id']],
    ];

    /** @var list<list<string>> Covered by support_tickets_{client_id,department_id}_status_last_reply_at_index and support_tickets_status_last_reply_at_index. */
    private const REDUNDANT_TICKET_INDEXES = [['client_id'], ['department_id'], ['status']];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $columns]) {
            OnlineIndex::create($table, $columns);
        }

        foreach (self::REDUNDANT_TICKET_INDEXES as $columns) {
            OnlineIndex::drop('support_tickets', $columns);
        }
    }

    public function down(): void
    {
        foreach (self::REDUNDANT_TICKET_INDEXES as $columns) {
            OnlineIndex::create('support_tickets', $columns);
        }

        foreach (array_reverse(self::INDEXES) as [$table, $columns]) {
            OnlineIndex::drop($table, $columns);
        }
    }
};
