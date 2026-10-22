<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote objects registrar drivers create for local records (registrars.md C4): customers, contacts, profiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrar_refs', function (Blueprint $table): void {
            $table->id();
            $table->string('registrar_key', 50);
            $table->string('ref_type', 20);
            $table->string('local_type', 40);
            $table->unsignedBigInteger('local_id');
            $table->string('remote_id', 191);
            // '' rather than null, so the unique index also holds on PostgreSQL (nulls are distinct there).
            $table->string('payload_hash', 64)->default('');
            $table->text('secret')->nullable();
            $table->timestamps();

            $table->unique(['registrar_key', 'ref_type', 'local_type', 'local_id', 'payload_hash'], 'registrar_refs_local_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrar_refs');
    }
};
