<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import accounts, step 2 (cPanel spec §10). `selections`: what staff chose per account (import it or not; client,
 * product, cycle, next due date). `reviewed`: the exact rows the review page showed (username, client, product, cycle,
 * next due date, price) and their digest; apply imports only those, and only while they still hold. `report`: the
 * queued import's result per account. Ids, dates and amounts only; deleted with the fetched list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_account_imports', function (Blueprint $table): void {
            $table->json('selections')->nullable();
            $table->json('reviewed')->nullable();
            $table->json('report')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_account_imports', function (Blueprint $table): void {
            $table->dropColumn(['selections', 'reviewed', 'report']);
        });
    }
};
