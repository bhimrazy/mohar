<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a client's (or a login's) email address last changed. Set by the models whenever the address changes; null
 * when it never has since this column was added. Features that trust an email match (account import) use it to flag
 * an address changed recently.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['clients', 'users'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->timestamp('email_changed_at')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['clients', 'users'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('email_changed_at'));
        }
    }
};
