<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Late payments (#130). late_payment_at: booked after the attempt closed. check_reason: why staff must check the
 * booking (late, credit, refund); late_checked_at: when staff marked it checked. lookups_closed_at: the late sweep
 * stops looking this attempt up (the provider says it can never be paid, or it closed before this upgrade).
 * Nullable columns are a metadata-only change on PostgreSQL; the indexes are built CONCURRENTLY there.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->timestamp('late_payment_at')->nullable();
            $table->string('check_reason', 20)->nullable();
            $table->timestamp('late_checked_at')->nullable();
            $table->timestamp('lookups_closed_at')->nullable();
        });

        // Attempts that closed before this upgrade are not swept for late payments (webhooks and staff still can).
        DB::table('payment_attempts')->whereIn('status', ['expired', 'failed'])->update(['lookups_closed_at' => now()]);

        // The late sweep filters closed attempts by created_at; the to-dos count rows with a check reason.
        OnlineIndex::create('payment_attempts', ['created_at']);
        OnlineIndex::create('payment_attempts', ['check_reason']);
    }

    public function down(): void
    {
        OnlineIndex::drop('payment_attempts', ['check_reason']);
        OnlineIndex::drop('payment_attempts', ['created_at']);

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn(['late_payment_at', 'check_reason', 'late_checked_at', 'lookups_closed_at']);
        });
    }
};
