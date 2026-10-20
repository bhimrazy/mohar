<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The .np manual-registration workflow (registrars.md §9): one row per domain handled by the np_manual driver,
 * and the registrant's identity documents (encrypted files on the private objects disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('np_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained('domains')->cascadeOnDelete();
            $table->string('stage', 32)->index();
            $table->string('applicant_type', 16)->nullable();
            $table->timestamp('stage_changed_at');
            $table->string('returned_reasons', 1000)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedSmallInteger('rejection_code')->nullable();
            $table->string('rejection_note', 1000)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('registry_expires_on')->nullable();
            $table->date('review_due_on')->nullable()->index();
            $table->timestamp('review_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('np_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('np_registration_id')->index()->constrained('np_registrations')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 64);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->date('delete_after')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('np_documents');
        Schema::dropIfExists('np_registrations');
    }
};
