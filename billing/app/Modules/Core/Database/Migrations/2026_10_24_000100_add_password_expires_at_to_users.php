<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a one-time password stops signing in (#60). Null for every existing login: a one-time password issued before
 * this migration does not expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        LockTimeout::run('users', function (): void {
            if (! Schema::hasColumn('users', 'password_expires_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->timestamp('password_expires_at')->nullable();
                });
            }
        });
    }

    public function down(): void
    {
        LockTimeout::run('users', function (): void {
            if (Schema::hasColumn('users', 'password_expires_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropColumn('password_expires_at');
                });
            }
        });
    }
};
