<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import accounts, step 2 (cPanel spec §10). `selections`: what staff chose per account (import it or not; client,
 * product, cycle, next due date). `reviewed`: the exact rows the review page showed (username, client, product, cycle,
 * next due date, price) and their digest; apply imports only those, and only while they still hold. The queued
 * import's result: one append-only line per account in `server_account_import_report_lines` (written with the
 * account's service), the counts and the reason it stopped, if it did, on the import row. Ids, dates, amounts and our
 * own messages only; deleted with the fetched list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_account_imports', function (Blueprint $table): void {
            $table->json('selections')->nullable();
            $table->json('reviewed')->nullable();
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('not_imported_count')->default(0);
            $table->string('stop_reason', 255)->nullable();
        });

        Schema::create('server_account_import_report_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_account_import_id')->constrained('server_account_imports')->cascadeOnDelete();
            $table->string('username', 255); // as services.username: any ListsAccounts driver, not only cPanel's 32
            $table->boolean('imported');
            $table->foreignId('service_id')->nullable()->index()->constrained('services')->nullOnDelete();
            $table->string('message', 255);
            $table->timestamp('created_at')->nullable();
            $table->index(['server_account_import_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_account_import_report_lines');
        Schema::table('server_account_imports', function (Blueprint $table): void {
            $table->dropColumn(['selections', 'reviewed', 'imported_count', 'not_imported_count', 'stop_reason']);
        });
    }
};
