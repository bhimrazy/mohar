<?php

use App\Modules\Core\Enums\ClientOrigin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How each client was created (ClientOrigin). Every creation path sets it; the default is the cautious one (sign-up:
 * an unverified address), so a path that forgets is flagged rather than trusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->string('origin', 20)->default(ClientOrigin::SignUp->value));

        $this->backfill();
    }

    /**
     * Existing clients: staff-created only where the audit log shows staff creating them (the admin form logs
     * client.created; client entries are never pruned). Every other client may have signed up, even one without an
     * owner login today (the login can be deleted), so it stays sign-up.
     */
    public function backfill(): void
    {
        DB::table('clients')
            ->whereIn('id', DB::table('activity_log')->select('client_id')->where('event', 'client.created')->where('actor_type', 'staff')->whereNotNull('client_id'))
            ->update(['origin' => ClientOrigin::Staff->value]);
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('origin'));
    }
};
