<?php

use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Migration\Models\MigrationRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Upgrades: stored email hashes (migration_records.email_hash, `email:<hash>` id-map rows) were keyed from APP_KEY.
 * Pin that key as the install secret now, from the configured key that reproduces a stored hash, before any rotation can change it. A fresh install has
 * no hashes and creates the secret at its first import check.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hashes = DB::table('migration_records')->whereNotNull('email_hash')->exists()
            || DB::table('migration_id_map')->where('source_id', 'like', 'email:%')->exists();

        // Throws InstallSecretUnavailable when several keys are configured and none can be checked: see emailHashKey().
        if ((string) config('app.key') !== '' && $hashes) {
            app(InstallSecrets::class)->get(MigrationRecord::EMAIL_HASH_SECRET);
        }
    }

    public function down(): void {}
};
