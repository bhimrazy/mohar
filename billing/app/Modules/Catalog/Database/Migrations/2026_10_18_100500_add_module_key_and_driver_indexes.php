<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR 0009 PR B2 database review (#65):
 * - a product's custom fields are read by module key (PriceBook::moduleInputs()), so a module key is unique per
 *   product (NULLs stay distinct on PostgreSQL and SQLite, so fields without a key are unaffected);
 * - PriceBook::productsForDriver() filters products by driver.
 * Built CONCURRENTLY on PostgreSQL (no write lock) and skipped when an index of the same name exists.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('product_custom_fields', ['product_id', 'module_key'], unique: true);
        OnlineIndex::create('products', ['provisioning_driver', 'id']);
    }

    public function down(): void
    {
        OnlineIndex::drop('products', ['provisioning_driver', 'id']);
        OnlineIndex::drop('product_custom_fields', ['product_id', 'module_key'], unique: true);
    }
};
