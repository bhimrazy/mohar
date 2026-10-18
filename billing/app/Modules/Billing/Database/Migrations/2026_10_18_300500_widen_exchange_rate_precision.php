<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gateway conversion rates stored on payments, payment batches and payment attempts widen to decimal(24,12), matching
 * currencies.exchange_rate, so a rate against a weak default currency keeps its significant digits. Widening keeps
 * every stored value; rolling back rounds to six decimals.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['transactions', 'payment_batches'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('exchange_rate', 24, 12)->nullable()->change();
            });
        }

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->decimal('fx_rate', 24, 12)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->decimal('fx_rate', 16, 6)->nullable()->change();
        });

        foreach (['transactions', 'payment_batches'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('exchange_rate', 16, 6)->nullable()->change();
            });
        }
    }
};
