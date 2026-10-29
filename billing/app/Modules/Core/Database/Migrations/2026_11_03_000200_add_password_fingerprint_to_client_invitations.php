<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fingerprint of the login's password hash when the invitation was mailed (#97): a password written without model
 * events voids the link too. Null on invitations sent before this migration, which keep the older checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        LockTimeout::run('client_invitations', function (): void {
            if (! Schema::hasColumn('client_invitations', 'password_fingerprint')) {
                Schema::table('client_invitations', function (Blueprint $table) {
                    $table->string('password_fingerprint', 64)->nullable();
                });
            }
        });
    }

    public function down(): void
    {
        LockTimeout::run('client_invitations', function (): void {
            if (Schema::hasColumn('client_invitations', 'password_fingerprint')) {
                Schema::table('client_invitations', function (Blueprint $table) {
                    $table->dropColumn('password_fingerprint');
                });
            }
        });
    }
};
