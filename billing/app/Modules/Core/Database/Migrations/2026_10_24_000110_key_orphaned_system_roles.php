<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up to the roles.key backfill (2026_10_18_000160, #60): a system role renamed before it got no key, so the
 * installer never found it, never reset it, and staff could not delete it. Such a role is given the key whose
 * built-in permissions it still has exactly, when no role holds that key; any other keyless system role becomes a
 * custom role (name, permissions and staff kept), which staff can edit or delete like any other.
 */
return new class extends Migration
{
    /** Key => built-in permissions, sorted, as in App\Modules\Core\Install\SystemRoles at the time of this migration. */
    private const PERMISSIONS = [
        'full_administrator' => ['*'],
        'billing' => ['billing.*', 'billing.credit.adjust', 'billing.payments.manage', 'billing.refunds.manage', 'billing.tax_notes.issue', 'billing.transactions.manage', 'core.clients.create', 'core.clients.update', 'core.clients.view', 'core.contacts.manage', 'orders.*', 'reports.*'],
        'support' => ['core.clients.view', 'domains.domains.view', 'kyc.documents.view', 'services.services.view', 'support.*'],
        'sales' => ['billing.quotes.manage', 'billing.quotes.view', 'core.clients.view', 'orders.orders.accept', 'orders.orders.view'],
    ];

    public function up(): void
    {
        $orphans = DB::table('roles')->where('is_system', true)->whereNull('key')->orderBy('id')->get(['id', 'permissions']);

        foreach ($orphans as $orphan) {
            $permissions = json_decode((string) $orphan->permissions, true);
            $permissions = is_array($permissions) ? $permissions : [];
            sort($permissions);
            $key = array_search($permissions, self::PERMISSIONS, true);

            if (is_string($key) && ! DB::table('roles')->where('key', $key)->exists()) {
                DB::table('roles')->where('id', $orphan->id)->update(['key' => $key]);

                continue;
            }

            DB::table('roles')->where('id', $orphan->id)->update(['is_system' => false]);
        }
    }

    /** Nothing: which roles were keyless system roles is not kept. */
    public function down(): void {}
};
