<?php

use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Enums\EmailSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How each client was created (ClientOrigin) and who set its current email (EmailSource). The defaults are the
 * cautious values (sign-up; set by the client), so a path that forgets is flagged rather than trusted. Outside a
 * transaction: the columns are added at once (metadata only on PostgreSQL), then existing rows are backfilled in
 * short batches, so `clients` is never locked for the whole backfill.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const BATCH = 1000;

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('origin', 20)->default(ClientOrigin::SignUp->value);
            $table->string('email_source', 20)->default(EmailSource::Client->value);
        });

        if (DB::getDriverName() === 'pgsql') {
            $values = fn (array $cases): string => implode(', ', array_map(fn (BackedEnum $case): string => "'{$case->value}'", $cases));
            DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_origin_check CHECK (origin IN ('.$values(ClientOrigin::cases()).'))');
            DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_email_source_check CHECK (email_source IN ('.$values(EmailSource::cases()).'))');
        }

        $this->backfill();
    }

    /**
     * Existing clients, from the activity log (client entries are never pruned):
     *
     * - origin is staff where a staff member created the client (client.created); sign-up otherwise;
     * - email_source is staff where the last entry that set the email was a staff member's (client.created, or
     *   client.updated with email among the changes); a client-area "account details" save counts as the client's
     *   (its entry does not say which fields changed), and so does every client with no such entry.
     */
    public function backfill(): void
    {
        DB::table('clients')->select('id')->chunkById(self::BATCH, function ($clients): void {
            $origin = [];
            $email = [];
            $entries = DB::table('activity_log')->whereIn('client_id', $clients->pluck('id')->all())
                ->where(fn ($query) => $query->whereIn('event', ['client.created', 'client.updated'])->orWhere('description', 'Client updated account details'))
                ->orderBy('id')->get(['client_id', 'event', 'actor_type', 'description', 'properties']);

            foreach ($entries as $entry) {
                $byStaff = $entry->actor_type === 'staff';

                if ($entry->event === 'client.created' && $byStaff) {
                    $origin[$entry->client_id] = true;
                    $email[$entry->client_id] = true;
                } elseif ($entry->event === 'client.updated' && $byStaff && in_array('email', json_decode((string) $entry->properties, true)['changed'] ?? [], true)) {
                    $email[$entry->client_id] = true;
                } elseif ($entry->description === 'Client updated account details') {
                    $email[$entry->client_id] = false;
                }
            }

            DB::transaction(function () use ($origin, $email): void {
                DB::table('clients')->whereIn('id', array_keys($origin))->update(['origin' => ClientOrigin::Staff->value]);
                DB::table('clients')->whereIn('id', array_keys(array_filter($email)))->update(['email_source' => EmailSource::Staff->value]);
            });
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_origin_check');
            DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_email_source_check');
        }

        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['origin', 'email_source']));
    }
};
