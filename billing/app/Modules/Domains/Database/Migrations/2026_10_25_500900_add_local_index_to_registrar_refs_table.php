<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Finds the refs of one local record without the registrar key (the client merge moves them by local owner).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        OnlineIndex::create('registrar_refs', ['local_type', 'local_id'], 'registrar_refs_local_idx');
    }

    public function down(): void
    {
        OnlineIndex::drop('registrar_refs', 'registrar_refs_local_idx');
    }
};
