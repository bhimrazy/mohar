<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What staff chose per account on Import accounts, step 2: import it or not, and the client, product, cycle and next
 * due date to import it with. Ids and dates only; kept with the fetched list and deleted with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_account_imports', function (Blueprint $table): void {
            $table->json('selections')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_account_imports', function (Blueprint $table): void {
            $table->dropColumn('selections');
        });
    }
};
