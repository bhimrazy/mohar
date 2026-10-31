<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Indexes for the billing day and the dashboards (database review 2026-10-31, H3, H4 and M1):
 *  - invoices_open_receivables_index: the open branch of the admin dashboard's receivables (BillingDashboard), read
 *    from the index alone; every selected column is a key because SQLite has no INCLUDE.
 *  - invoices_cancelled_at_cancelled_index: that query's cancelled branch, by cancelled_at.
 *  - invoices_open_id_index: the billing-day tasks (late fees, capture, reminders, auto-cancel) take open invoices
 *    by id in chunks; without it the planner walks the primary key and filters out every closed invoice.
 *  - invoices_client_id_issue_date_id_index: the client invoice list and dashboard, newest first (read backwards).
 * The open predicate is InvoiceStatus::payable() as the queries bind it; a query whose statuses are not a subset of
 * it cannot use the partial indexes (HotPathIndexesTest). Built concurrently on PostgreSQL; deletes nothing.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const OPEN = "status in ('unpaid', 'payment_pending', 'collections')";

    public function up(): void
    {
        OnlineIndex::create('invoices', ['status', 'currency', 'balance', 'issue_date', 'due_date'], 'invoices_open_receivables_index', where: self::OPEN);
        OnlineIndex::create('invoices', ['cancelled_at'], 'invoices_cancelled_at_cancelled_index', where: "status = 'cancelled'");
        OnlineIndex::create('invoices', ['id'], 'invoices_open_id_index', where: self::OPEN);
        OnlineIndex::create('invoices', ['client_id', 'issue_date', 'id']);
    }

    public function down(): void
    {
        OnlineIndex::drop('invoices', ['client_id', 'issue_date', 'id']);
        OnlineIndex::drop('invoices', 'invoices_open_id_index');
        OnlineIndex::drop('invoices', 'invoices_cancelled_at_cancelled_index');
        OnlineIndex::drop('invoices', 'invoices_open_receivables_index');
    }
};
