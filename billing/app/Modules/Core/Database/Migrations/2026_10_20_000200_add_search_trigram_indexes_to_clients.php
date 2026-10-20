<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Client search (Client::scopeSearch, the client picker, admin global search) matches lower() of these four columns
 * anywhere (Like::where).
 * PostgreSQL only (trigram GIN indexes, built CONCURRENTLY: no write lock); SQLite keeps scanning.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::trigram('clients', 'lower(first_name)', 'clients_first_name_trgm_index');
        OnlineIndex::trigram('clients', 'lower(last_name)', 'clients_last_name_trgm_index');
        OnlineIndex::trigram('clients', 'lower(company_name)', 'clients_company_name_trgm_index');
        OnlineIndex::trigram('clients', 'lower(email)', 'clients_email_trgm_index');
    }

    public function down(): void
    {
        OnlineIndex::drop('clients', 'clients_first_name_trgm_index');
        OnlineIndex::drop('clients', 'clients_last_name_trgm_index');
        OnlineIndex::drop('clients', 'clients_company_name_trgm_index');
        OnlineIndex::drop('clients', 'clients_email_trgm_index');
    }
};
