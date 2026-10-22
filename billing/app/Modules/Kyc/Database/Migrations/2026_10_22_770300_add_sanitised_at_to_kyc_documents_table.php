<?php

use App\Modules\Core\Support\LockTimeout;
use App\Modules\Core\Support\OnlineIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (#287). `sanitised_at` marks a document whose images were re-encoded by ImageSanitiser: at upload since
 * #286, and by `kyc:reencode-images` for documents stored before it. `sanitise_issue` flags a document whose images
 * could not be re-encoded (left as stored, for staff). `kyc_image_rewrites` records an image file written but not yet
 * switched to, so a run killed in between leaves no stray file; it holds paths only, no image data.
 *
 * The index is partial (`where sanitised_at is null`, built concurrently on PostgreSQL): it serves the command's keyset
 * query in id order and shrinks to the documents still to do.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Outside a transaction (the concurrent index), so each step can be repeated after a failed run. The guard is
        // inside the closure: LockTimeout runs it again on each attempt (architecture rule 5).
        LockTimeout::run('kyc_documents', function (): void {
            if (Schema::hasColumn('kyc_documents', 'sanitised_at')) {
                return;
            }

            Schema::table('kyc_documents', function (Blueprint $table): void {
                $table->timestamp('sanitised_at')->nullable();
                $table->string('sanitise_issue', 20)->nullable();
            });
        });

        OnlineIndex::create('kyc_documents', ['id'], 'kyc_documents_unsanitised_index', where: 'sanitised_at is null');

        if (Schema::hasTable('kyc_image_rewrites')) {
            return;
        }

        Schema::create('kyc_image_rewrites', function (Blueprint $table): void {
            $table->id();
            // No foreign key: the record must outlive a document deleted mid-run, so its file can still be removed.
            $table->unsignedBigInteger('document_id')->index();
            $table->string('old_path', 100);
            $table->string('new_path', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_image_rewrites');

        OnlineIndex::drop('kyc_documents', 'kyc_documents_unsanitised_index');

        LockTimeout::run('kyc_documents', function (): void {
            if (! Schema::hasColumn('kyc_documents', 'sanitised_at')) {
                return;
            }

            Schema::table('kyc_documents', function (Blueprint $table): void {
                $table->dropColumn(['sanitised_at', 'sanitise_issue']);
            });
        });
    }
};
