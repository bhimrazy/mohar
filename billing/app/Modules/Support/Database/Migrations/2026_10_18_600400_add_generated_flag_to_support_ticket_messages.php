<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks Message-IDs we generated ourselves: only replies threaded on one of these (plus an exact
 * sender match) count as verified inbound replies (spec §6.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->boolean('email_message_id_generated')->default(false);
        });

        DB::table('support_ticket_messages')
            ->whereNotNull('email_message_id')
            ->where('source', '!=', 'email')
            ->update(['email_message_id_generated' => true]);
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropColumn('email_message_id_generated');
        });
    }
};
