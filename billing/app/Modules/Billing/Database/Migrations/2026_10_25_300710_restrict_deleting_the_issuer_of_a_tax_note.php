<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VAT PR 1b: a filed note names its issuer for good, so deleting a staff user who issued one is refused instead of
 * blanking `tax_notes.created_by`. The key moves from SET NULL to RESTRICT. Nothing writes notes before this release, so
 * the table is empty and no existing row is affected; the previous release never reads the table.
 *
 * Tightening a constraint is normally a contract step (docs/architecture.md); it is safe here only because the table has
 * no rows and no code of the previous release uses it.
 *
 * @compatible the table is empty and the previous release never reads it. deploy:check-migrations does not recognise a
 * tightened foreign key and would call this expand only (issue #389).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->rekey(restrict: true);
    }

    public function down(): void
    {
        // 300700, rolled back next, refuses while a note exists; refusing here first leaves both in place.
        if (DB::table('tax_notes')->exists()) {
            throw new RuntimeException('Rolling back the note migrations is refused while credit and debit notes exist. Restore a backup instead.');
        }

        $this->rekey(restrict: false);
    }

    private function rekey(bool $restrict): void
    {
        LockTimeout::run('tax_notes', function () use ($restrict): void {
            Schema::table('tax_notes', function (Blueprint $table) use ($restrict): void {
                $table->dropForeign(['created_by']);
                $key = $table->foreign('created_by')->references('id')->on('users');
                $restrict ? $key->restrictOnDelete() : $key->nullOnDelete();
            });
        });
    }
};
