<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * support_tickets.first_unanswered_at (#51): the time of the oldest customer reply that staff have not answered, kept
 * on the ticket so the "Awaiting reply" queue sorts by an index instead of two subqueries per ticket. PostReply
 * sets it on a customer reply while it is empty and clears it on a staff reply.
 *
 * The queue orders by (is_pinned desc, first_unanswered_at asc nulls last, id desc). The index has the same order,
 * so PostgreSQL reads the first page from it and applies the status and department filters on the way. It covers only
 * tickets that are not closed and not merged (the page's filter; a closed ticket keeps its value after a last customer
 * message, and those rows would otherwise be read and discarded first). A
 * (status, first_unanswered_at) index cannot do that: the awaiting statuses are a setting, so a page spans several.
 * SQLite cannot declare NULLS LAST in an index and sorts the page itself.
 *
 * Expand only: the column is nullable and the previous release ignores it. A reply that release records while both run
 * leaves the column stale; `support:recompute-unanswered --since=<deploy start>` fixes those rows once the old release stops.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const BATCH = 1000;

    private const INDEX = 'support_tickets_awaiting_queue_index';

    public function up(): void
    {
        if (! Schema::hasColumn('support_tickets', 'first_unanswered_at')) {
            LockTimeout::run('support_tickets', fn () => Schema::table('support_tickets', fn (Blueprint $table) => $table->timestamp('first_unanswered_at')->nullable()));
        }

        $this->backfill();

        OnlineIndex::create('support_tickets', [], self::INDEX, expression: DB::connection()->getDriverName() === 'pgsql'
            ? 'is_pinned desc, first_unanswered_at asc nulls last, id desc'
            : 'is_pinned desc, first_unanswered_at, id desc', where: "status <> 'closed' and merged_into_id is null");
    }

    /**
     * Repeatable: only empty values are filled. Each batch is one short statement over a primary-key range, and the
     * subquery reads support_ticket_messages through its (ticket_id, created_at) index.
     */
    public function backfill(): void
    {
        $last = (int) DB::table('support_tickets')->max('id');

        for ($from = 1; $from <= $last; $from += self::BATCH) {
            DB::update(
                "update support_tickets set first_unanswered_at = (
                    select min(m.created_at) from support_ticket_messages m
                    where m.ticket_id = support_tickets.id and m.type = 'reply' and m.author_type in ('client', 'guest')
                    and (support_tickets.last_staff_reply_at is null or m.created_at > support_tickets.last_staff_reply_at)
                ) where id between ? and ? and first_unanswered_at is null and merged_into_id is null",
                [$from, $from + self::BATCH - 1],
            );
        }
    }

    public function down(): void
    {
        OnlineIndex::drop('support_tickets', self::INDEX);

        if (Schema::hasColumn('support_tickets', 'first_unanswered_at')) {
            LockTimeout::run('support_tickets', fn () => Schema::table('support_tickets', fn (Blueprint $table) => $table->dropColumn('first_unanswered_at')));
        }
    }
};
