<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "how to register" guide per TLD the client registers at the registry; empty falls back to the
 * domains.self_registration_url setting. Expand only: nullable, the previous release ignores it.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('tlds', 'how_to_url')) {
            LockTimeout::run('tlds', fn () => Schema::table('tlds', fn (Blueprint $table) => $table->string('how_to_url', 255)->nullable()));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tlds', 'how_to_url')) {
            LockTimeout::run('tlds', fn () => Schema::table('tlds', fn (Blueprint $table) => $table->dropColumn('how_to_url')));
        }
    }
};
