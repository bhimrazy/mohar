<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress of `security:reencrypt` per target (a column or a set of files), so an interrupted run resumes after the
 * last finished chunk. A checkpoint belongs to the APP_KEY it was written under (`key_fingerprint`, an HMAC that
 * reveals nothing about the key); a run under another key starts that target again. Rows are deleted when a run
 * completes. Holds no ciphertext or plaintext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reencryption_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('target', 150)->unique();
            $table->string('key_fingerprint', 64);
            $table->string('last_key', 191)->nullable();
            $table->boolean('completed')->default(false);
            $table->json('counts');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reencryption_checkpoints');
    }
};
