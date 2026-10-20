<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel user a fetch signed in as, kept with the list so "Match again" and the import compare owners with the
 * connection that listed the accounts, not with whatever the server's credentials say later. Nullable: lists fetched
 * before this column hold none and are matched without the owner check until fetched again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_account_imports', function (Blueprint $table): void {
            $table->string('connection_user', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_account_imports', function (Blueprint $table): void {
            $table->dropColumn('connection_user');
        });
    }
};
