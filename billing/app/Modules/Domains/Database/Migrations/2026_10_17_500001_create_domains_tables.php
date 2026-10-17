<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tlds', function (Blueprint $table) {
            $table->id();
            $table->string('extension', 63)->unique();
            $table->string('display_unicode', 63)->nullable();
            $table->string('registrar_key', 50)->nullable()->index();
            $table->boolean('auto_register')->default(true);
            $table->boolean('epp_required')->default(true);
            $table->unsignedTinyInteger('min_years')->default(1);
            $table->unsignedTinyInteger('max_years')->default(10);
            $table->boolean('allow_register')->default(true);
            $table->boolean('allow_transfer')->default(true);
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->bigInteger('grace_fee')->default(0);
            $table->unsignedSmallInteger('redemption_days')->default(0);
            $table->bigInteger('redemption_fee')->default(0);
            $table->unsignedSmallInteger('renew_window_days')->nullable();
            $table->boolean('addon_dns')->default(false);
            $table->boolean('addon_id_protection')->default(false);
            $table->boolean('addon_email_forwarding')->default(false);
            $table->boolean('is_spotlight')->default(false);
            $table->string('badge', 10)->nullable();
            $table->json('additional_fields')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('tld_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tld_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->foreignId('client_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('years');
            $table->bigInteger('register')->nullable();
            $table->bigInteger('transfer')->nullable();
            $table->bigInteger('renew')->nullable();
            $table->timestamps();
            $table->unique(['tld_id', 'currency', 'client_group_id', 'years']);
        });

        Schema::create('registrar_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->text('settings')->nullable();
            $table->boolean('test_mode')->default(false);
            $table->timestamps();
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->foreignId('tld_id')->constrained()->restrictOnDelete();
            $table->string('name', 253)->index();
            $table->string('active_name', 253)->nullable()->unique();
            $table->string('name_unicode', 253);
            $table->string('sld', 63);
            $table->string('tld', 63);
            $table->string('type', 20);
            $table->string('registrar_key', 50)->nullable()->index();
            $table->string('status', 30)->index();
            $table->date('registration_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->date('next_due_date')->index();
            $table->date('next_invoice_date')->index();
            $table->unsignedTinyInteger('billing_anchor_day')->nullable();
            $table->unsignedTinyInteger('period_years')->default(1);
            $table->bigInteger('first_payment_amount')->default(0);
            $table->bigInteger('recurring_amount')->default(0);
            $table->char('currency', 3);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('gateway_key', 50)->nullable();
            $table->boolean('auto_renew')->default(true)->index();
            $table->boolean('id_protection')->default(false);
            $table->boolean('dns_management')->default(false);
            $table->boolean('email_forwarding')->default(false);
            $table->boolean('registrar_lock')->nullable();
            $table->json('nameservers')->nullable();
            $table->text('epp_code')->nullable();
            $table->boolean('is_premium')->default(false);
            $table->bigInteger('premium_renew_cost')->nullable();
            $table->unsignedBigInteger('linked_service_id')->nullable()->index();
            $table->string('linked_service_status', 30)->nullable();
            $table->date('linked_service_due_date')->nullable();
            $table->unsignedBigInteger('provision_invoice_id')->nullable();
            $table->json('additional_fields')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->string('last_sync_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('needs_review')->default(false)->index();
            $table->json('registrar_meta')->nullable();
            $table->timestamp('transfer_stale_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'status']);
            $table->index(['status', 'next_invoice_date']);
            $table->index(['status', 'expiry_date']);
        });

        Schema::create('domain_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('company', 150)->nullable();
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->char('country', 2)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['domain_id', 'role']);
        });

        Schema::create('domain_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('kind', 20);
            $table->date('period_start');
            $table->unsignedTinyInteger('years')->default(1);
            $table->string('status', 20)->default('open')->index();
            $table->timestamps();
            $table->unique(['domain_id', 'invoice_id']);
        });

        Schema::create('domain_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('expiry_date');
            $table->smallInteger('offset_days');
            $table->string('template_key', 100);
            $table->json('recipients');
            $table->timestamp('dispatched_at');
            $table->unique(['domain_id', 'expiry_date', 'offset_days']);
        });

        Schema::create('registrar_command_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('registrar_key', 50);
            $table->string('command', 50);
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->boolean('success');
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrar_command_log');
        Schema::dropIfExists('domain_reminders');
        Schema::dropIfExists('domain_invoices');
        Schema::dropIfExists('domain_contacts');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('registrar_accounts');
        Schema::dropIfExists('tld_prices');
        Schema::dropIfExists('tlds');
    }
};
