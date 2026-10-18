<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('headline')->nullable();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_hidden')->default(false);
            $table->json('allowed_gateways')->nullable();
            $table->string('order_form_template')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tagline')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('payment_type', 20)->default('recurring');
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_retired')->default(false)->index();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('requires_domain')->default(false);
            $table->boolean('stock_enabled')->default(false);
            $table->integer('stock_quantity')->default(0);
            $table->string('quantity_mode', 30)->default('single');
            $table->unsignedInteger('recurring_cycles_limit')->default(0);
            $table->unsignedInteger('fixed_term_days')->default(0);
            $table->string('fixed_term_email_key')->nullable();
            $table->string('welcome_email_key')->nullable();
            $table->string('upgrade_email_key')->nullable();
            $table->string('auto_setup', 20)->default('on_payment');
            $table->string('provisioning_driver', 50)->default('none');
            $table->unsignedBigInteger('server_group_id')->nullable()->index();
            $table->json('driver_config')->nullable();
            $table->boolean('allow_config_option_upgrades')->default(false);
            $table->boolean('prorata_enabled')->default(false);
            $table->unsignedTinyInteger('prorata_day')->default(1);
            $table->unsignedTinyInteger('prorata_charge_next_month_day')->default(0);
            $table->string('free_domain', 20)->default('none');
            $table->json('free_domain_cycles')->nullable();
            $table->json('free_domain_tlds')->nullable();
            $table->unsignedInteger('suspend_days_override')->nullable();
            $table->unsignedInteger('terminate_days_override')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['product_group_id', 'sort_order']);
        });

        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->string('priceable_type', 40);
            $table->unsignedBigInteger('priceable_id');
            $table->char('currency', 3);
            $table->string('cycle', 20);
            $table->boolean('enabled')->default(true);
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('setup_fee')->default(0);
            $table->timestamps();
            $table->unique(['priceable_type', 'priceable_id', 'currency', 'cycle']);
            $table->index('currency');
        });

        Schema::create('config_option_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('config_option_group_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_option_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unique(['config_option_group_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('config_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('module_key')->nullable();
            $table->string('type', 20);
            $table->unsignedInteger('quantity_min')->nullable();
            $table->unsignedInteger('quantity_max')->nullable();
            $table->unsignedInteger('quantity_step')->default(1);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('config_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_option_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('module_value')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('module_key')->nullable();
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->string('description')->nullable();
            $table->string('validation_regex', 500)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_admin_only')->default(false);
            $table->boolean('show_on_order')->default(true);
            $table->boolean('show_on_invoice')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind', 20)->default('independent');
            $table->string('payment_type', 20)->default('recurring');
            $table->boolean('is_taxable')->default(true);
            $table->boolean('show_on_order')->default(true);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_retired')->default(false)->index();
            $table->boolean('suspend_parent_on_overdue')->default(false);
            $table->string('welcome_email_key')->nullable();
            $table->integer('weight')->default(0);
            $table->boolean('allow_quantity')->default(false);
            $table->boolean('prorata_with_parent')->default(false);
            $table->string('auto_setup', 20)->default('on_payment');
            $table->string('provisioning_driver', 50)->default('none');
            $table->json('driver_config')->nullable();
            $table->timestamps();
        });

        Schema::create('addon_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unique(['addon_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('product_upgrade_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_product_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['product_id', 'target_product_id']);
            $table->index('target_product_id');
        });

        Schema::create('product_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_reservations');
        Schema::dropIfExists('product_upgrade_paths');
        Schema::dropIfExists('addon_product');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('product_custom_fields');
        Schema::dropIfExists('config_option_values');
        Schema::dropIfExists('config_options');
        Schema::dropIfExists('config_option_group_product');
        Schema::dropIfExists('config_option_groups');
        Schema::dropIfExists('prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_groups');
    }
};
