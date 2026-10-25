<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expiry bookkeeping of stored integration credentials (ADR 0009 Amendment (#489)): one row per certificate setting,
 * written by the host that stores it, so the daily expiry check and the dashboard read expiry dates and the warning
 * already sent without decrypting the integration's settings. A new table: expand only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('family', 20);
            $table->string('owner_key', 50);
            $table->string('setting_key', 64);
            $table->string('serial', 80);
            $table->timestamp('not_after');
            $table->smallInteger('warned_stage')->nullable();
            $table->timestamps();

            $table->unique(['family', 'owner_key', 'setting_key']);
            $table->index(['family', 'not_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
