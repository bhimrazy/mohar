<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Orders whose stock must be returned (cancelled / fraud). Lets reservations recorded after the
         * cancellation (OrderPlaced arriving after OrderMarkedFraud) be released immediately.
         */
        Schema::create('product_stock_order_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->timestamps();
        });

        Schema::table('config_options', function (Blueprint $table) {
            $table->index('config_option_group_id');
        });

        Schema::table('config_option_values', function (Blueprint $table) {
            $table->index('config_option_id');
        });

        Schema::table('product_custom_fields', function (Blueprint $table) {
            $table->index('product_id');
        });

        Schema::table('product_stock_reservations', function (Blueprint $table) {
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_stock_reservations', fn (Blueprint $table) => $table->dropIndex(['product_id']));
        Schema::table('product_custom_fields', fn (Blueprint $table) => $table->dropIndex(['product_id']));
        Schema::table('config_option_values', fn (Blueprint $table) => $table->dropIndex(['config_option_id']));
        Schema::table('config_options', fn (Blueprint $table) => $table->dropIndex(['config_option_group_id']));
        Schema::dropIfExists('product_stock_order_releases');
    }
};
