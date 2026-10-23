<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domains under a TLD the client registers at the registry are renewed there: auto-renew was on by default and
 * would show "on" (and, before this change, be invoiced). down() turns it back on for the same rows; rows that were
 * already off before up() are turned on too, since the earlier value is not recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('domains')->whereIn('tld_id', DB::table('tlds')->where('registered_by_client', true)->select('id'))->where('auto_renew', true)->update(['auto_renew' => false]);
    }

    public function down(): void
    {
        DB::table('domains')->whereIn('tld_id', DB::table('tlds')->where('registered_by_client', true)->select('id'))->where('auto_renew', false)->update(['auto_renew' => true]);
    }
};
