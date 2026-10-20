<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * At most one website draft (security review L3): a partial unique index, supported by PostgreSQL and SQLite. Two
 * first saves at the same moment can no longer both insert a draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX storefront_revisions_one_draft ON storefront_revisions (status) WHERE status = 'draft'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX storefront_revisions_one_draft');
    }
};
