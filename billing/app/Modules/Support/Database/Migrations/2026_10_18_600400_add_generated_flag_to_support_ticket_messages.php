<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks Message-IDs we generated ourselves (and keeps a hash of a merged ticket's retired access key): only replies threaded on one of these (plus an exact
 * sender match) count as verified inbound replies (spec §6.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->boolean('email_message_id_generated')->default(false);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            /** sha256 of the access key a merged ticket had before rotation: old guest links only show "merged". */
            $table->char('merged_access_key_hash', 64)->nullable();
        });

        DB::table('support_ticket_messages')
            ->whereNotNull('email_message_id')
            ->where('source', '!=', 'email')
            ->update(['email_message_id_generated' => true]);
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('merged_access_key_hash');
        });

        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropColumn('email_message_id_generated');
        });
    }
};
