<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One fetch of the accounts on a server for Import accounts (cPanel spec §10): what the panel reported and how each
 * account matched when it was fetched, kept for 24 hours while staff review it. The account list holds client emails,
 * so it is encrypted at rest (longText: it grows with the server); the per-category counts are plain, so a page can
 * show them without decrypting the list. `missing` holds the ids of live services whose account the server did not
 * report. A server keeps at most its latest fetch; rows are deleted when they expire or the server is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_account_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->longText('accounts')->nullable();
            $table->unsignedInteger('account_count')->default(0);
            $table->unsignedInteger('proposed_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('reseller_count')->default(0);
            $table->unsignedInteger('linked_count')->default(0);
            $table->json('missing')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['server_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_account_imports');
    }
};
