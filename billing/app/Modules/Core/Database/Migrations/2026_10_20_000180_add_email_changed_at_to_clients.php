<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a client's email address last changed. Set by the model whenever the address changes; null when it never has
 * since this column was added. Features that trust an email match (account import) use it to flag an address changed
 * recently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->timestamp('email_changed_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('email_changed_at'));
    }
};
