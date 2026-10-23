<?php

use App\Modules\Billing\Services\FiscalBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * VAT PR 1a (ADR 0024 §2): fills `numbered_at`, the value snapshot, the reporting rate, the supply class, the Bikram
 * Sambat fields and `credited_total` / `debited_total` for invoices numbered before the columns existed, and writes the
 * staff-review list. In chunks of 200 invoices, each a short transaction on its own rows; it does not lock the table.
 * Measured on PostgreSQL on a developer laptop: 20,000 invoices in about 40 seconds (2 ms each).
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
