<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('client_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->string('promotion_code', 50)->nullable();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('items');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('type', 20);
            $table->decimal('value', 10, 2)->default(0);
            $table->boolean('recurring')->default(false);
            $table->unsignedInteger('recur_count')->default(0);
            $table->json('applies_to');
            $table->json('requires');
            $table->boolean('requires_existing_ok')->default(false);
            $table->json('cycles')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedInteger('max_uses')->default(0);
            $table->unsignedInteger('uses')->default(0);
            $table->boolean('apply_once')->default(false);
            $table->boolean('once_per_client')->default(false);
            $table->boolean('new_clients_only')->default(false);
            $table->boolean('existing_clients_only')->default(false);
            $table->boolean('lifetime')->default(false);
            $table->boolean('allow_upgrades')->default(false);
            $table->string('upgrade_scope', 20)->default('product');
            $table->bigInteger('min_cart_amount')->nullable();
            $table->boolean('discount_setup_fee')->default(false);
            $table->text('admin_notes')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20)->default('new');
            $table->string('status', 20)->default('pending')->index();
            $table->char('currency', 3);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('setup_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promotion_code', 50)->nullable();
            $table->string('payment_gateway', 50)->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent')->nullable();
            $table->string('referral_code', 50)->nullable();
            $table->foreignId('placed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('placed_by_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->string('fraud_provider', 30)->nullable();
            $table->decimal('fraud_score', 5, 2)->nullable();
            $table->string('fraud_decision', 20)->nullable()->index();
            $table->json('fraud_payload')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('addon_id')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('order_items')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('description');
            $table->string('domain')->nullable()->index();
            $table->string('cycle', 20)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('first_amount')->default(0);
            $table->bigInteger('recurring_amount')->default(0);
            $table->bigInteger('setup_fee')->default(0);
            $table->bigInteger('discount_amount')->default(0);
            $table->unsignedInteger('promotion_cycles_remaining')->nullable();
            $table->string('auto_setup', 20)->nullable();
            $table->boolean('stock_reserved')->default(false);
            $table->timestamps();
            $table->index(['item_type', 'item_id']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20)->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->bigInteger('discount_amount')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->index(['promotion_id', 'client_id']);
        });

        Schema::create('fraud_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->decimal('score', 5, 2)->default(0);
            $table->string('decision', 20);
            $table->json('reasons');
            $table->json('raw')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_checks');
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('carts');
    }
};
