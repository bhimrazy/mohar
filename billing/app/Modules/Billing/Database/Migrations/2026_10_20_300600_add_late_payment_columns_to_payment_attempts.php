<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Late payments (#130): a provider success that arrives after the attempt closed is booked, then flagged until
 * staff check it. late_payment_at is when it was booked late; late_checked_at is when staff marked it checked.
 * Nullable columns are a metadata-only change on PostgreSQL; the index is built CONCURRENTLY there.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->timestamp('late_payment_at')->nullable();
            $table->timestamp('late_checked_at')->nullable();
        });

        OnlineIndex::create('payment_attempts', ['late_payment_at']);
    }

    public function down(): void
    {
        OnlineIndex::drop('payment_attempts', ['late_payment_at']);

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn(['late_payment_at', 'late_checked_at']);
        });
    }
};
