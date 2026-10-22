<?php

use App\Modules\Core\Support\LockTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (#251). payment_attempts.resume_url: the provider page a driver marked resumable (Khalti's payment_url,
 * which its lookup doesn't return), kept so "Continue payment" can reopen it. Encrypted by the model's cast, so it is
 * text (the ciphertext is longer than the URL). Nullable, no default: a metadata-only change on PostgreSQL.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // The guard is inside the closure: LockTimeout runs it again on each attempt (architecture rule 5).
        LockTimeout::run('payment_attempts', function (): void {
            if (Schema::hasColumn('payment_attempts', 'resume_url')) {
                return;
            }

            Schema::table('payment_attempts', fn (Blueprint $table) => $table->text('resume_url')->nullable());
        });
    }

    public function down(): void
    {
        LockTimeout::run('payment_attempts', function (): void {
            if (! Schema::hasColumn('payment_attempts', 'resume_url')) {
                return;
            }

            Schema::table('payment_attempts', fn (Blueprint $table) => $table->dropColumn('resume_url'));
        });
    }
};
