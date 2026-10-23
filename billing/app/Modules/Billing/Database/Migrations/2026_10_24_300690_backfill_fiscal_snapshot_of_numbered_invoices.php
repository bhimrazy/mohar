<?php

use App\Modules\Billing\Services\FiscalBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * VAT PR 1a (ADR 0024 §2): fills `numbered_at`, the value snapshot, the reporting rate, the supply class, the Bikram
 * Sambat fields and `credited_total` / `debited_total` for invoices numbered before the columns existed, and writes the
 * staff-review list. In chunks of 200 invoices, each a short transaction on its own rows; it does not lock the table.
 * About 10,000 invoices take under a minute; the work is a few queries per chunk.
 *
 * It only reads numbered invoices without `numbered_at` and never writes a number, so it can be run again, here or with
 * `php artisan billing:fiscal-snapshot`. Run that after the deploy has switched: the previous release can number an
 * invoice while the migration is already done.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        app(FiscalBackfill::class)->run();
    }

    /** Nothing to undo: the columns this filled are dropped by the migration that added them. */
    public function down(): void {}
};
