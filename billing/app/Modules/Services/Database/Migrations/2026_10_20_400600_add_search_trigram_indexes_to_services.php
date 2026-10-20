<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Service search (Service::scopeSearch, admin global search) matches these four columns anywhere; all four need an
 * index, or PostgreSQL scans the table for the OR.
 * PostgreSQL only (trigram GIN indexes, built CONCURRENTLY: no write lock); SQLite keeps scanning.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::trigram('services', 'domain', 'services_domain_trgm_index');
        OnlineIndex::trigram('services', 'username', 'services_username_trgm_index');
        OnlineIndex::trigram('services', 'product_name', 'services_product_name_trgm_index');
        OnlineIndex::trigram('services', 'dedicated_ip', 'services_dedicated_ip_trgm_index');
    }

    public function down(): void
    {
        OnlineIndex::drop('services', 'services_domain_trgm_index');
        OnlineIndex::drop('services', 'services_username_trgm_index');
        OnlineIndex::drop('services', 'services_product_name_trgm_index');
        OnlineIndex::drop('services', 'services_dedicated_ip_trgm_index');
    }
};
