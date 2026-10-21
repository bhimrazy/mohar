<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TLDs the client registers at the registry themselves (.np and its second levels): never sold here, only used with
 * hosting as an existing domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tlds', function (Blueprint $table): void {
            $table->boolean('registered_by_client')->default(false);
        });

        DB::table('tlds')->where('extension', '.np')->orWhere('extension', 'like', '%.np')->update(['registered_by_client' => true]);
    }

    public function down(): void
    {
        Schema::table('tlds', function (Blueprint $table): void {
            $table->dropColumn('registered_by_client');
        });
    }
};
