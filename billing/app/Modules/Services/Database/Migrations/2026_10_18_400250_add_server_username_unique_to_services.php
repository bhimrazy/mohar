<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * An account username is unique per server among the services that hold an account there (pending, active,
 * suspended, not deleted), cPanel review on #69: a create may move a service to a new name, and two live services must
 * never hold the same one. Terminated, cancelled and deleted services are left out, because the panel frees a name
 * when it removes the account, so a live service may reuse it (and imports bring such pairs). NULL server ids and
 * usernames stay distinct on PostgreSQL and SQLite.
 * Built CONCURRENTLY on PostgreSQL (no write lock). It fails only when two live services on one server already share a
 * username; rename one first.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'services_server_username_live_unique';

    public function up(): void
    {
        OnlineIndex::create('services', ['server_id', 'username'], self::NAME, unique: true, where: "status in ('pending', 'active', 'suspended') and deleted_at is null");
    }

    public function down(): void
    {
        OnlineIndex::drop('services', self::NAME, unique: true);
    }
};
