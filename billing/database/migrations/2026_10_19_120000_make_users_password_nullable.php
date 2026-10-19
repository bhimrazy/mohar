<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A client login created through a sign-in provider has no password until the user sets one (ADR 0018 §5). Laravel
 * refuses a null hash at login, in confirm-password and in the current_password rule. Staff logins always keep one
 * (the User saving hook).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->changePassword(nullable: true);
    }

    public function down(): void
    {
        // Fails while passwordless logins exist; give them a password (or remove them) first.
        $this->changePassword(nullable: false);
    }

    /**
     * PostgreSQL alters the column in place. SQLite rebuilds the table, which cannot carry the lower(email) expression
     * index, so that index is dropped and recreated around the rebuild.
     */
    private function changePassword(bool $nullable): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($sqlite) {
            OnlineIndex::drop('users', 'users_email_lower_index');
        }

        Schema::table('users', function (Blueprint $table) use ($nullable): void {
            $table->string('password')->nullable($nullable)->change();
        });

        if ($sqlite) {
            OnlineIndex::create('users', [], 'users_email_lower_index', expression: 'lower(email)');
        }
    }
};
