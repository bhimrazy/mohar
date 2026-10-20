<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client import (client-import spec §4). A source is a named origin; each upload is a run; each file row is a staging
 * record. Cells and anything derived from them are encrypted (`raw`, `normalized`, `before`, `header`, `found_values`);
 * the only plain derivative of an email is its keyed hash. Counts are plain columns so pages never decrypt rows to
 * show them. The id map links what was imported from a source to the record it became, so re-runs never duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 16);
            $table->string('name', 100);
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('migration_source_id')->constrained('migration_sources')->restrictOnDelete();
            $table->string('source_kind', 16);
            $table->string('entity', 16);
            $table->string('status', 16)->index();
            // Bumped by every new check, so a check still queued from an earlier mapping stops.
            $table->unsignedInteger('generation')->default(0);
            $table->string('file_key')->nullable();
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_bytes');
            $table->string('delimiter', 1);
            $table->longText('header')->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->longText('found_values')->nullable();
            $table->char('review_digest', 64)->nullable();
            foreach (['row', 'staged', 'checked', 'create', 'update', 'unchanged', 'skip', 'decision', 'error', 'warning', 'tax_exempt', 'created', 'updated', 'skipped', 'failed'] as $count) {
                $table->unsignedInteger("{$count}_count")->default(0);
            }
            $table->string('error', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('undo_until')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['migration_source_id', 'file_sha256']);
        });

        Schema::create('migration_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->longText('raw')->nullable();
            $table->longText('normalized')->nullable();
            $table->char('email_hash', 64)->nullable();
            // The id-map key: the mapped External ID, or "email:<email_hash>" when none is mapped.
            $table->string('source_id', 191)->nullable();
            $table->char('checksum', 64)->nullable();
            $table->string('status', 16)->nullable();
            $table->string('decision', 16)->nullable();
            $table->foreignId('match_client_id')->nullable()->index()->constrained('clients')->nullOnDelete();
            $table->json('problems')->nullable();
            $table->json('changes')->nullable();
            $table->string('outcome', 16)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->longText('before')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unique(['migration_run_id', 'row_number']);
            $table->index(['migration_run_id', 'decision']);
            $table->index(['migration_run_id', 'status']);
            $table->index(['migration_run_id', 'email_hash']);
            $table->index(['migration_run_id', 'source_id']);
        });

        Schema::create('migration_id_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('migration_source_id')->constrained('migration_sources')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->string('source_id', 191);
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->char('checksum', 64);
            $table->foreignId('first_run_id')->nullable()->index()->constrained('migration_runs')->nullOnDelete();
            $table->foreignId('last_run_id')->nullable()->index()->constrained('migration_runs')->nullOnDelete();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['migration_source_id', 'source_type', 'source_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_id_map');
        Schema::dropIfExists('migration_records');
        Schema::dropIfExists('migration_runs');
        Schema::dropIfExists('migration_sources');
    }
};
