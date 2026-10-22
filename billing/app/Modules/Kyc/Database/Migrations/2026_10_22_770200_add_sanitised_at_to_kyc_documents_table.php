<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (#287). `sanitised_at` marks a document whose images were re-encoded by ImageSanitiser: at upload since
 * #286, and by `kyc:reencode-images` for documents stored before it. `sanitise_issue` flags a document whose images
 * could not be re-encoded (left as stored, for staff). `kyc_image_rewrites` records an image file written but not yet
 * switched to, so a run killed in between leaves no stray file; it holds paths only, no image data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->timestamp('sanitised_at')->nullable();
            $table->string('sanitise_issue', 20)->nullable();

            $table->index(['sanitised_at', 'sanitise_issue', 'id'], 'kyc_documents_unsanitised_index');
        });

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

        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->dropIndex('kyc_documents_unsanitised_index');
            $table->dropColumn(['sanitised_at', 'sanitise_issue']);
        });
    }
};
