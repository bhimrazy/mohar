<?php

use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stable key for the system roles, so the installer finds them after staff rename them and never mistakes a
 * custom role for one (PR #57). Custom roles keep a null key. Existing system roles are matched once, by the names
 * they were seeded with; the unique index is built CONCURRENTLY on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** Seeded name => key, as in App\Modules\Core\Install\SystemRoles at the time of this migration. */
    private const SYSTEM_ROLES = [
        'Full Administrator' => 'full_administrator',
        'Billing' => 'billing',
        'Support' => 'support',
        'Sales' => 'sales',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'key')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('key', 50)->nullable()->after('id');
            });
        }

        foreach (self::SYSTEM_ROLES as $name => $key) {
            DB::table('roles')->where('is_system', true)->where('name', $name)->whereNull('key')->update(['key' => $key]);
        }

        OnlineIndex::create('roles', ['key'], 'roles_key_unique', unique: true);
    }

    public function down(): void
    {
        OnlineIndex::drop('roles', 'roles_key_unique');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('key');
        });
    }
};
