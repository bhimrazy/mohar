<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review state kept in plain columns, so a decision never decrypts rows (client-import spec §5.5): whether the row
 * sets tax exemption, whether staff chose to link an existing login, and the row's review hash, from which the run's
 * digest is kept up to date. The outcome index is built online in the next migration (980210).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_records', function (Blueprint $table): void {
            $table->boolean('tax_exempt')->default(false);
            $table->boolean('link_login')->nullable();
            $table->char('review_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('migration_records', fn (Blueprint $table) => $table->dropColumn(['tax_exempt', 'link_login', 'review_hash']));
    }
};
