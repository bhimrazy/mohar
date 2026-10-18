<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('fill_type', 30)->default('least_full');
            $table->timestamps();
        });

        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname');
            $table->string('ip_address', 45)->nullable();
            $table->json('assigned_ips')->nullable();
            $table->string('driver', 50)->index();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->text('api_token')->nullable();
            $table->text('access_hash')->nullable();
            $table->boolean('secure')->default(true);
            $table->unsignedInteger('port')->nullable();
            $table->unsignedInteger('max_accounts')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false);
            $table->json('nameservers')->nullable();
            $table->bigInteger('monthly_cost')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('datacenter')->nullable();
            $table->string('status_url')->nullable();
            $table->timestamps();
        });

        Schema::create('server_group_server', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['server_group_id', 'server_id']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('product_name')->nullable();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('cycle', 20);
            $table->char('currency', 3);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('domain')->nullable()->index();
            $table->foreignId('server_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('username')->nullable()->index();
            $table->text('password')->nullable();
            $table->string('dedicated_ip', 45)->nullable();
            $table->json('assigned_ips')->nullable();
            $table->bigInteger('first_payment_amount')->default(0);
            $table->bigInteger('recurring_amount')->default(0);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->unsignedInteger('promotion_cycles_remaining')->nullable();
            $table->bigInteger('renewal_discount')->default(0);
            $table->date('registration_date');
            $table->unsignedTinyInteger('billing_anchor_day')->nullable();
            $table->date('next_due_date')->nullable()->index();
            $table->date('next_invoice_date')->nullable()->index();
            $table->unsignedInteger('renewals_invoiced')->default(0);
            $table->unsignedInteger('recurring_cycles_limit')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->date('suspend_exempt_until')->nullable();
            $table->unsignedInteger('suspend_days_override')->nullable();
            $table->unsignedInteger('terminate_days_override')->nullable();
            $table->boolean('do_not_renew')->default(false);
            $table->boolean('terminate_at_period_end')->default(false);
            $table->date('termination_date')->nullable();
            $table->string('payment_gateway', 50)->nullable();
            $table->json('config_options')->nullable();
            $table->text('custom_fields')->nullable();
            $table->json('driver_data')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'next_due_date']);
        });

        Schema::create('service_config_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('option_id');
            $table->unsignedBigInteger('value_id');
            $table->unique(['service_id', 'option_id']);
            $table->index(['option_id', 'value_id']);
        });

        Schema::create('service_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('addon_id')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('cycle', 20);
            $table->char('currency', 3);
            $table->bigInteger('setup_fee')->default(0);
            $table->bigInteger('recurring_amount')->default(0);
            $table->date('registration_date');
            $table->unsignedTinyInteger('billing_anchor_day')->nullable();
            $table->date('next_due_date')->nullable()->index();
            $table->date('next_invoice_date')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->date('suspend_exempt_until')->nullable();
            $table->boolean('suspend_parent_on_overdue')->default(false);
            $table->date('termination_date')->nullable();
            $table->json('driver_data')->nullable();
            $table->timestamps();
        });

        Schema::create('service_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_addon_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('kind', 20);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->string('status', 20)->default('open')->index();
            $table->timestamps();
            $table->index(['service_id', 'period_start']);
            $table->index(['service_addon_id', 'period_start']);
        });

        Schema::create('service_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->json('from_snapshot');
            $table->json('to_snapshot');
            $table->char('currency', 3);
            $table->bigInteger('credit_amount')->default(0);
            $table->bigInteger('charge_amount')->default(0);
            $table->bigInteger('amount_due')->default(0);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status', 20)->default('pending_payment')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('reason')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('by_staff')->default(false);
            $table->string('status', 20)->default('open')->index();
            $table->date('process_after')->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('module_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_addon_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('driver', 50);
            $table->string('command', 50);
            $table->string('initiator', 50);
            $table->unsignedInteger('attempt')->default(1);
            $table->boolean('success');
            $table->string('status', 20)->default('succeeded')->index();
            $table->text('error')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('module_queue', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('command', 50);
            $table->string('kind', 20)->default('failure');
            $table->text('args')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('service_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('disk_used_mb')->default(0);
            $table->unsignedBigInteger('disk_limit_mb')->nullable();
            $table->unsignedBigInteger('bw_used_mb')->default(0);
            $table->unsignedBigInteger('bw_limit_mb')->nullable();
            $table->timestamp('captured_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_usage_snapshots');
        Schema::dropIfExists('module_queue');
        Schema::dropIfExists('module_commands');
        Schema::dropIfExists('cancellation_requests');
        Schema::dropIfExists('service_changes');
        Schema::dropIfExists('service_invoices');
        Schema::dropIfExists('service_addons');
        Schema::dropIfExists('service_config_options');
        Schema::dropIfExists('services');
        Schema::dropIfExists('server_group_server');
        Schema::dropIfExists('servers');
        Schema::dropIfExists('server_groups');
    }
};
