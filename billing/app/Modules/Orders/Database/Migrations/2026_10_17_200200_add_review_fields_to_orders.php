<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('years')->default(1)->after('cycle');
            $table->boolean('taxable')->default(true)->after('discount_amount');
            $table->unsignedBigInteger('renewal_discount')->default(0)->after('promotion_cycles_remaining');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['years', 'taxable', 'renewal_discount']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('idempotency_fingerprint');
        });
    }
};
