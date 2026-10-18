<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every rate that was in force, for the history chart and audits (one row per change).
        Schema::create('exchange_rate_history', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->decimal('rate', 16, 6);
            $table->string('source', 30);
            $table->timestamp('effective_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['currency', 'effective_at']);
            $table->index('user_id');
        });

        // Fetched rates that moved more than the allowed daily change: held for staff to approve or reject.
        Schema::create('exchange_rate_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->decimal('current_rate', 16, 6);
            $table->decimal('proposed_rate', 16, 6);
            $table->decimal('change_percent', 10, 4);
            $table->string('source', 30);
            $table->timestamp('effective_at');
            $table->string('status', 20)->default('pending');
            // Set to the currency while pending, null once decided: at most one pending review per currency.
            $table->char('pending_currency', 3)->nullable()->unique();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'currency']);
            $table->index('decided_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_reviews');
        Schema::dropIfExists('exchange_rate_history');
    }
};
