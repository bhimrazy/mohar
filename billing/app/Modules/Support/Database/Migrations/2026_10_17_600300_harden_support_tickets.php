<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review round: unverified-requester flag and explicit indexes for foreign keys and queue queries
 * (PostgreSQL does not index foreign keys automatically).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->boolean('is_unverified_requester')->default(false);
            $table->index('client_id');
            $table->index('department_id');
            $table->index('assigned_to');
            $table->index('merged_into_id');
            $table->index(['department_id', 'status', 'last_reply_at']);
            $table->index(['client_id', 'status', 'last_reply_at']);
        });

        Schema::table('support_ticket_attachments', function (Blueprint $table) {
            $table->index('message_id');
        });

        Schema::table('support_inbound_emails', function (Blueprint $table) {
            $table->index('ticket_id');
        });

        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('support_inbound_emails', function (Blueprint $table) {
            $table->dropIndex(['ticket_id']);
        });

        Schema::table('support_ticket_attachments', function (Blueprint $table) {
            $table->dropIndex(['message_id']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'status', 'last_reply_at']);
            $table->dropIndex(['department_id', 'status', 'last_reply_at']);
            $table->dropIndex(['merged_into_id']);
            $table->dropIndex(['assigned_to']);
            $table->dropIndex(['department_id']);
            $table->dropIndex(['client_id']);
            $table->dropColumn('is_unverified_requester');
        });
    }
};
