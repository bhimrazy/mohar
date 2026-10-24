<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finds the refs of one local record without the registrar key (the client merge moves them by local owner).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrar_refs', function (Blueprint $table): void {
            $table->index(['local_type', 'local_id'], 'registrar_refs_local_idx');
        });
    }

    public function down(): void
    {
        Schema::table('registrar_refs', function (Blueprint $table): void {
            $table->dropIndex('registrar_refs_local_idx');
        });
    }
};
