<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * An account username is unique per server (cPanel review on #69): a create may move a service to a new name, and two
 * services must never hold the same one. Trashed services keep their names, as the username generator assumes.
 * NULL server ids and usernames stay distinct on PostgreSQL and SQLite, so services without either are unaffected.
 * Built CONCURRENTLY on PostgreSQL (no write lock). It fails when duplicates already exist; rename them first.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('services', ['server_id', 'username'], unique: true);
    }

    public function down(): void
    {
        OnlineIndex::drop('services', ['server_id', 'username'], unique: true);
    }
};
