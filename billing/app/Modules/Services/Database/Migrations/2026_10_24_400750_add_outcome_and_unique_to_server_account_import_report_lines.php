<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (#134, #149 L1). `not_reached` marks the line a stopped import writes for an account it never reached, so
 * the page no longer compares the translated message (a default of false: the previous release inserts lines without
 * it). A unique (import, username) index makes a chunk that runs twice unable to report an account twice. A report
 * keeps one line per account, so this deletes any later duplicate line before the index is built (the earliest stays)
 * and recounts the imports it touched; the table holds the last import's lines only. Lines written before this upgrade
 * keep not_reached false, so an unfinished report across the upgrade counts its unreached accounts as failed.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        LockTimeout::run('server_account_import_report_lines', function (): void {
            if (! Schema::hasColumn('server_account_import_report_lines', 'not_reached')) {
                Schema::table('server_account_import_report_lines', fn (Blueprint $table) => $table->boolean('not_reached')->default(false));
            }
        });

        $lines = fn () => DB::table('server_account_import_report_lines');
        $affected = $lines()->whereNotIn('id', fn ($query) => $query->from('server_account_import_report_lines')->selectRaw('min(id)')->groupBy('server_account_import_id', 'username'))
            ->distinct()->pluck('server_account_import_id');

        foreach ($affected as $importId) {
            $lines()->where('server_account_import_id', $importId)->whereNotIn('id', fn ($query) => $query->from('server_account_import_report_lines')->selectRaw('min(id)')
                ->where('server_account_import_id', $importId)->groupBy('username'))->delete();
            DB::table('server_account_imports')->where('id', $importId)->update([
                'imported_count' => $lines()->where('server_account_import_id', $importId)->where('imported', true)->count(),
                'not_imported_count' => $lines()->where('server_account_import_id', $importId)->where('imported', false)->count(),
            ]);
        }

        OnlineIndex::create('server_account_import_report_lines', ['server_account_import_id', 'username'], 'sair_lines_import_username_unique', unique: true);
    }

    public function down(): void
    {
        OnlineIndex::drop('server_account_import_report_lines', 'sair_lines_import_username_unique');
        LockTimeout::run('server_account_import_report_lines', function (): void {
            if (Schema::hasColumn('server_account_import_report_lines', 'not_reached')) {
                Schema::table('server_account_import_report_lines', fn (Blueprint $table) => $table->dropColumn('not_reached'));
            }
        });
    }
};
