<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Database audit 2026-10-18 (docs/database/audit-2026-10-18.md): foreign key without an index.
 * Built CONCURRENTLY on PostgreSQL (no write lock) and skipped when an index of the same name exists.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<array{0: string, 1: list<string>}> */
    private const INDEXES = [
        ['staff_invitations', ['invited_by']],
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
