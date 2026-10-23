<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Indexes for the VAT registers (VAT PR 6): numbered invoices by BS period and by AD issue date, the two ways a
 * register period selects them. Partial on `numbered_at is not null`, so drafts, proformas and add-funds invoices are
 * not indexed. Built CONCURRENTLY on PostgreSQL. `tax_notes` has both indexes already.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public const BS_PERIOD = 'invoices_register_bs_period_index';

    public const ISSUE_DATE = 'invoices_register_issue_date_index';

    public function up(): void
    {
        OnlineIndex::create('invoices', ['bs_period'], self::BS_PERIOD, where: 'numbered_at is not null');
        OnlineIndex::create('invoices', ['issue_date'], self::ISSUE_DATE, where: 'numbered_at is not null');
    }

    public function down(): void
    {
        OnlineIndex::drop('invoices', self::BS_PERIOD);
        OnlineIndex::drop('invoices', self::ISSUE_DATE);
    }
};
