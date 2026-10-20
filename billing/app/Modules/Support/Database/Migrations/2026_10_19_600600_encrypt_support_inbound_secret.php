<?php

use App\Modules\Core\Support\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * support.inbound_secret became a secret setting (security review 2026-10-19, B1): encrypt a value stored in
 * plaintext before then. Runs with every module booted, so the definition is registered. Operators still rotate the
 * key, because the old value was readable by every role with support settings access.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(Settings::class)->encryptStoredSecrets();
    }

    public function down(): void
    {
        // Encrypted values stay readable through Settings::get(); nothing to undo.
    }
};
