<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('sequence', 30);
            $table->string('period', 10)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['sequence', 'period']);
        });

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedTinyInteger('level')->default(1);
            $table->char('country', 2)->nullable();
            $table->string('state', 100)->nullable();
            // null = any location; home_state / other_state = intra- vs inter-state supply in the home country (India GST).
            $table->string('scope', 20)->nullable();
            $table->decimal('rate', 6, 3);
            $table->integer('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->timestamps();
            $table->index(['level', 'country']);
        });

        Schema::create('gateways', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('display_name', 100);
            $table->boolean('is_active')->default(false);
            $table->boolean('show_on_order_form')->default(true);
            $table->integer('sort_order')->default(0);
            $table->char('convert_to_currency', 3)->nullable();
            $table->text('config')->nullable();
            $table->timestamps();
        });

        Schema::create('pay_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 50);
            $table->string('kind', 20)->default('card');
            $table->text('token');
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->unsignedBigInteger('billing_contact_id')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'gateway']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('number', 50)->nullable()->unique();
            $table->string('proforma_number', 50)->nullable()->unique();
            $table->string('status', 20)->default('draft');
            $table->string('kind', 20)->default('standard');
            $table->string('source', 20)->default('manual');
            $table->char('currency', 3);
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->string('tax1_name', 100)->nullable();
            $table->decimal('tax1_rate', 6, 3)->default(0);
            $table->bigInteger('tax1_amount')->default(0);
            $table->string('tax2_name', 100)->nullable();
            $table->decimal('tax2_rate', 6, 3)->default(0);
            $table->bigInteger('tax2_amount')->default(0);
            $table->boolean('tax_compound')->default(false);
            $table->boolean('tax_inclusive')->default(false);
            $table->string('tax_exempt_reason', 20)->nullable();
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('credit_applied')->default(0);
            $table->bigInteger('adjustments_total')->default(0);
            $table->bigInteger('balance')->default(0);
            $table->string('payment_gateway', 50)->nullable();
            $table->json('client_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('last_capture_attempt_at')->nullable();
            $table->unsignedInteger('capture_attempts')->default(0);
            $table->date('first_capture_failure_on')->nullable();
            $table->json('reminder_log')->nullable();
            $table->timestamp('late_fee_applied_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'due_date']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('manual');
            $table->unsignedBigInteger('billable_id')->nullable();
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('amount');
            $table->boolean('taxable')->default(true);
            $table->bigInteger('tax_amount')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            // Double-invoicing guard "type:billable_id:period_start", nulled when the line/invoice is cancelled.
            $table->string('period_guard', 120)->nullable()->unique();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'billable_id']);
        });

        Schema::create('invoice_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('number', 50)->nullable()->unique();
            $table->string('reason', 30);
            $table->string('description')->nullable();
            $table->bigInteger('amount');
            $table->bigInteger('taxable_amount')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('transaction_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->json('invoice_ids');
            $table->bigInteger('total');
            $table->string('status', 20)->default('pending');
            $table->string('gateway', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pay_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 50);
            $table->string('gateway_txn_id', 191)->nullable();
            $table->string('type', 30);
            $table->string('status', 20)->default('succeeded');
            $table->bigInteger('amount_in')->default(0);
            $table->bigInteger('amount_out')->default(0);
            $table->bigInteger('fee')->default(0);
            $table->char('currency', 3);
            $table->bigInteger('gateway_amount')->nullable();
            $table->char('gateway_currency', 3)->nullable();
            $table->decimal('exchange_rate', 16, 6)->nullable();
            $table->bigInteger('refundable_remaining')->default(0);
            $table->foreignId('parent_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('description')->nullable();
            $table->json('raw')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['gateway', 'gateway_txn_id', 'type']);
            $table->index(['client_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('gateway', 50);
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->bigInteger('document_amount');
            $table->char('document_currency', 3);
            $table->decimal('fx_rate', 16, 6)->nullable();
            $table->string('provider_ref', 191)->nullable();
            $table->string('provider_txn_id', 191)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('review_reason', 191)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_lookup_at')->nullable();
            $table->unsignedInteger('lookup_count')->default(0);
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'provider_txn_id']);
            $table->index(['status', 'last_lookup_at']);
        });

        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reason', 30);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'currency', 'id']);
        });

        Schema::create('gateway_log', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 50)->index();
            $table->string('direction', 20);
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->string('result', 100);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('billing_client_flags', function (Blueprint $table) {
            $table->foreignId('client_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestamp('first_order_accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->nullable()->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('stage', 20)->default('draft');
            $table->char('currency', 3);
            $table->date('valid_until')->nullable();
            $table->text('proposal_text')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->string('tax1_name', 100)->nullable();
            $table->decimal('tax1_rate', 6, 3)->default(0);
            $table->bigInteger('tax1_amount')->default(0);
            $table->string('tax2_name', 100)->nullable();
            $table->decimal('tax2_rate', 6, 3)->default(0);
            $table->bigInteger('tax2_amount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['stage', 'valid_until']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->bigInteger('amount');
            $table->boolean('taxable')->default(true);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('billing_client_flags');
        Schema::dropIfExists('gateway_log');
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_batches');
        Schema::dropIfExists('invoice_adjustments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('pay_methods');
        Schema::dropIfExists('gateways');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('billing_sequences');
    }
};
