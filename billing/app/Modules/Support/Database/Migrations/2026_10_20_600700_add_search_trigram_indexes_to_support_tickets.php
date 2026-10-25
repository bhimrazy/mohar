<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Ticket lookup in the admin global search matches number and subject anywhere.
 * PostgreSQL only (trigram GIN indexes, built CONCURRENTLY: no write lock); SQLite keeps scanning.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::trigram('support_tickets', 'number', 'support_tickets_number_trgm_index');
        OnlineIndex::trigram('support_tickets', 'subject', 'support_tickets_subject_trgm_index');
    }

    public function down(): void
    {
        OnlineIndex::drop('support_tickets', 'support_tickets_number_trgm_index');
        OnlineIndex::drop('support_tickets', 'support_tickets_subject_trgm_index');
    }
};
