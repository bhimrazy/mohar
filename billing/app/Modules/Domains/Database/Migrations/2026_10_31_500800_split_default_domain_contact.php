<?php

use App\Data\Domains\Registrar\ContactSet;
use App\Modules\Core\Support\SettingList;
use App\Modules\Core\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The default domain contact moves from one JSON setting (domains.default_contact) to one setting per field
 * (domains.default_contact.<field>). The old row is kept for the previous release during a blue-green deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = DB::table('settings')->where('key', 'domains.default_contact')->first();

        // Nothing saved: the config default applies before and after.
        if ($legacy === null || DB::table('settings')->whereIn('key', array_map(fn (string $field): string => 'domains.default_contact.'.$field, ContactSet::FIELDS))->exists()) {
            return;
        }

        // Every field is written, blanks included, so a contact saved empty (client profile used) stays empty.
        $contact = SettingList::map(json_decode((string) $legacy->value, true));
        $rows = [];

        foreach (ContactSet::FIELDS as $field) {
            $value = $contact[$field] ?? null;
            $rows[] = ['key' => 'domains.default_contact.'.$field, 'value' => json_encode(is_scalar($value) ? (string) $value : null), 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('settings')->insert($rows);
        app(Settings::class)->forgetCached();
    }

    public function down(): void
    {
        // The per-field rows are harmless to the previous release, which never reads them.
    }
};
