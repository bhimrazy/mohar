<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Invoice search (Invoice::scopeSearch, admin global search) matches number and proforma_number anywhere.
 * PostgreSQL only (trigram GIN indexes, built CONCURRENTLY: no write lock); SQLite keeps scanning.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::trigram('invoices', 'number', 'invoices_number_trgm_index');
        OnlineIndex::trigram('invoices', 'proforma_number', 'invoices_proforma_number_trgm_index');
    }

    public function down(): void
    {
        OnlineIndex::drop('invoices', 'invoices_number_trgm_index');
        OnlineIndex::drop('invoices', 'invoices_proforma_number_trgm_index');
    }
};
