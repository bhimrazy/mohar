<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Random per-install secrets that must outlive an APP_KEY rotation (Core\Support\InstallSecrets): keys of stored
 * hashes, which a new APP_KEY would otherwise change. Encrypted with APP_KEY like every stored secret, so
 * `security:reencrypt` moves them to a new key without changing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('install_secrets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('install_secrets');
    }
};
