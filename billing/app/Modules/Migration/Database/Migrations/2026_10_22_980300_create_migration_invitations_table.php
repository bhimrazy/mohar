<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations staff queued from a client import (client-import spec §5.7): one row per login and request, holding
 * ids and a status only. (status, sent_at) serves the hourly throttle count, (status, id) the sender's next batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
            $table->foreignId('client_id')->index()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->string('status', 16);
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'sent_at']);
            $table->index(['status', 'id']);
            $table->index(['migration_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_invitations');
    }
};
