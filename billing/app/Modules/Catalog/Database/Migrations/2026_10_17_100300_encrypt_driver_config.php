<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provisioning driver settings may hold credentials, so they are stored encrypted (`encrypted:array` cast).
 * The ciphertext is not JSON, so the json column becomes text; existing rows are encrypted in place.
 */
return new class extends Migration
{
    private const TABLES = ['products', 'addons'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->text('driver_config_encrypted')->nullable());

            DB::table($table)->whereNotNull('driver_config')->orderBy('id')->chunkById(200, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->driver_config, true);
                    DB::table($table)->where('id', $row->id)->update([
                        'driver_config_encrypted' => Crypt::encryptString((string) json_encode(is_array($decoded) ? $decoded : [])),
                    ]);
                }
            });

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('driver_config'));
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn('driver_config_encrypted', 'driver_config'));
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->json('driver_config_plain')->nullable());

            DB::table($table)->whereNotNull('driver_config')->orderBy('id')->chunkById(200, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['driver_config_plain' => Crypt::decryptString((string) $row->driver_config)]);
                }
            });

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('driver_config'));
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn('driver_config_plain', 'driver_config'));
        }
    }
};
