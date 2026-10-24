<?php

use App\Modules\Core\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A fixed late fee moves from billing.late_fee.amount (minor units when the type was "fixed") to its own setting,
 * billing.late_fee.fixed_amount. The old row is kept for the previous release during a blue-green deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('settings')->whereIn('key', ['billing.late_fee.type', 'billing.late_fee.amount', 'billing.late_fee.fixed_amount'])->pluck('value', 'key');
        $type = json_decode((string) ($rows['billing.late_fee.type'] ?? 'null'), true);
        $amount = json_decode((string) ($rows['billing.late_fee.amount'] ?? 'null'), true);

        if ($type !== 'fixed' || ! is_numeric($amount) || $rows->has('billing.late_fee.fixed_amount')) {
            return;
        }

        DB::table('settings')->insert(['key' => 'billing.late_fee.fixed_amount', 'value' => json_encode((int) round((float) $amount)), 'created_at' => now(), 'updated_at' => now()]);
        app(Settings::class)->forgetCached();
    }

    public function down(): void
    {
        // The copy is harmless to the previous release, which never reads it.
    }
};
