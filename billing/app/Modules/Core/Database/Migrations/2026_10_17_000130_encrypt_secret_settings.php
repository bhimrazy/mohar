<?php

use App\Modules\Core\Support\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * Encrypt secret settings saved in plaintext before encryption at rest existed.
 * Runs with every module booted, so all secret definitions are registered.
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
