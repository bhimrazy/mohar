<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (VAT PR 1a, ADR 0024 §1, §2, §5): credit and debit notes, their lines, the refund reservations that
 * become notes, and the staff-review list of the fiscal backfill. The tables are empty until PR 1b and PR 4 write
 * to them. A foreign key locks the table it points at, so each table that points at an existing one is created under
 * LockTimeout, named after the busiest table it references. Fiscal documents are never deleted: every key that points
 * at a note, its invoice or its client is restrictOnDelete.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->create('invoices', 'tax_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 10);
            $table->string('number', 50)->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->date('issue_date');
            $table->timestamp('issued_at');
            $table->string('invoice_number', 50);
            $table->string('invoice_bs_issue_date', 10)->nullable();
            $table->string('reason_code', 30);
            $table->string('reason_text', 500);
            $table->bigInteger('taxable_amount')->default(0);
            $table->bigInteger('exempt_amount')->default(0);
            $table->bigInteger('tax1_amount')->default(0);
            $table->bigInteger('tax2_amount')->default(0);
            $table->bigInteger('total');
            $table->char('report_currency', 3)->nullable();
            $table->decimal('report_rate', 24, 12)->nullable();
            $table->string('supply_class', 10);
            $table->json('seller_snapshot')->nullable();
            $table->json('buyer_snapshot')->nullable();
            $table->string('bs_issue_date', 10);
            $table->string('bs_period', 7);
            $table->string('bs_fiscal_year', 8);
            $table->boolean('automatic')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('print_count')->default(0);
            $table->string('cbms_status', 20)->nullable();
            $table->string('cbms_reference', 100)->nullable();
            $table->timestamps();
            $table->index('invoice_id');
            $table->index('client_id');
            $table->index('bs_period');
            $table->index('issue_date');
        });

        $this->create('invoice_items', 'tax_note_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_note_id')->constrained()->restrictOnDelete();
            // Null only for a late-fee debit note, which belongs to no invoice line.
            $table->foreignId('invoice_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('amount');
            $table->boolean('taxable')->default(true);
            $table->bigInteger('tax1_amount')->default(0);
            $table->bigInteger('tax2_amount')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('tax_note_id');
            $table->index('invoice_item_id');
        });

        $this->create('transactions', 'tax_note_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            // The pending refund row; its id is the reference sent to the provider. One reservation per refund row.
            $table->foreignId('transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->string('reason_code', 30);
            $table->string('reason_text', 500)->nullable();
            $table->string('digest', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pending'); // pending|finalised|released
            $table->foreignId('tax_note_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['invoice_id', 'status']);
            $table->index('tax_note_id');
        });

        $this->create('invoice_items', 'tax_note_reservation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->constrained('tax_note_reservations')->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('amount');
            $table->boolean('taxable')->default(true);
            $table->bigInteger('tax1_amount')->default(0);
            $table->bigInteger('tax2_amount')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('reservation_id');
            $table->index('invoice_item_id');
        });

        // The staff-review list of the fiscal backfill. Nepal VAT mode cannot be enabled until staff have seen it.
        // (invoice, reason, subject) is the key, so a repeated run finds its rows instead of adding them again.
        $this->create('invoices', 'invoice_fiscal_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('reason', 40);
            $table->string('subject', 40)->default('invoice'); // "invoice", or "adjustment:<id>"
            $table->text('detail')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->foreignId('seen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['invoice_id', 'reason', 'subject']);
            $table->index('seen_at');
        });
    }

    public function down(): void
    {
        foreach (['invoice_fiscal_reviews', 'tax_note_reservation_lines', 'tax_note_reservations', 'tax_note_lines', 'tax_notes'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * @param  Closure(Blueprint): void  $columns
     */
    private function create(string $locks, string $table, Closure $columns): void
    {
        LockTimeout::run($locks, function () use ($table, $columns): void {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $columns);
            }
        });
    }
};
