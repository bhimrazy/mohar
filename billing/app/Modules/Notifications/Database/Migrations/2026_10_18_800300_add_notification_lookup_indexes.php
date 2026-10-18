<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Lookup indexes for the email log, client history, dashboard, retention purge, webhook delivery log and the
 * retry scan (built concurrently on PostgreSQL; ADR 0006, database audit PR #53).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('email_messages', ['client_id', 'id']);                 // admin log by client, newest first
        OnlineIndex::create('email_messages', ['client_id', 'status', 'sent_at']);  // client email history
        OnlineIndex::create('email_messages', ['status', 'updated_at']);            // failed-email dashboard to-do
        OnlineIndex::create('email_messages', ['body_purged_at', 'created_at']);    // retention purge
        OnlineIndex::create('webhook_deliveries', ['webhook_endpoint_id', 'id']);   // endpoint delivery log
        OnlineIndex::create('webhook_deliveries', ['next_attempt_at', 'id'], 'webhook_deliveries_due_index', where: "status in ('pending', 'failed') and next_attempt_at is not null");
    }

    public function down(): void
    {
        OnlineIndex::drop('webhook_deliveries', 'webhook_deliveries_due_index');
        OnlineIndex::drop('webhook_deliveries', ['webhook_endpoint_id', 'id']);
        OnlineIndex::drop('email_messages', ['body_purged_at', 'created_at']);
        OnlineIndex::drop('email_messages', ['status', 'updated_at']);
        OnlineIndex::drop('email_messages', ['client_id', 'status', 'sent_at']);
        OnlineIndex::drop('email_messages', ['client_id', 'id']);
    }
};
