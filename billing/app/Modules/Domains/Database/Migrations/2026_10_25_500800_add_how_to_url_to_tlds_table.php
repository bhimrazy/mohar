<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "how to register" guide per TLD the client registers at the registry; empty falls back to
 * the domains.self_registration_url setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tlds', function (Blueprint $table): void {
            $table->string('how_to_url', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tlds', function (Blueprint $table): void {
            $table->dropColumn('how_to_url');
        });
    }
};
