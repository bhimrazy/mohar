<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last server check of a "verify" item (ReconcilesCommands, ADR 0009 amendment 1): what the driver found, when,
 * and which attempt (module command row) the answer describes. Staff see it on the failed-actions queue, and "Create
 * again" waits for a recent not-executed answer about the latest attempt. Nullable and without defaults, so adding
 * them rewrites no rows; nothing filters on them. The attempt reference is cleared when the module log is pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_queue', function (Blueprint $table): void {
            $table->string('lookup_status', 20)->nullable();
            $table->string('lookup_message', 255)->nullable();
            $table->timestamp('looked_up_at')->nullable();
            $table->foreignId('lookup_command_id')->nullable()->index()->constrained('module_commands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('module_queue', function (Blueprint $table): void {
            // Foreign key, then its index, then the columns: SQLite will not drop a column an index still covers.
            $table->dropForeign(['lookup_command_id']);
            $table->dropIndex(['lookup_command_id']);
            $table->dropColumn(['lookup_status', 'lookup_message', 'looked_up_at', 'lookup_command_id']);
        });
    }
};
