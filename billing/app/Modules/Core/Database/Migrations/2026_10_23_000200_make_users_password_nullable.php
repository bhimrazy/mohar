<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A login created by a social or magic-link sign-in has no password (ADR 0018 §5).
 *
 * @compatible Dropping NOT NULL: the previous release only ever writes a password and reads a null as "no match".
 */
return new class extends Migration
{
    public function up(): void
    {
        $change = fn () => Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            LockTimeout::run('users', $change);

            return;
        }

        // SQLite rebuilds the table and cannot copy an expression index through the rebuild.
        OnlineIndex::drop('users', 'users_email_lower_index');
        $change();
        OnlineIndex::create('users', [], 'users_email_lower_index', expression: 'lower(email)');
    }
};
