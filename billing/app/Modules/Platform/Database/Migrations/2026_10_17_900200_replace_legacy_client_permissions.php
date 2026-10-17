<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "core.clients.manage" was split into explicit create/update permissions. Rewrite existing roles so
 * deployed installs keep their access, and so nothing relies on the removed implicit alias.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'permissions'])->each(function (object $role): void {
            $permissions = json_decode((string) $role->permissions, true) ?: [];

            if (! in_array('core.clients.manage', $permissions, true)) {
                return;
            }

            $permissions = array_values(array_unique([
                ...array_diff($permissions, ['core.clients.manage']),
                'core.clients.create',
                'core.clients.update',
            ]));

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });
    }

    public function down(): void
    {
        // Irreversible data rewrite; the explicit permissions remain valid.
    }
};
