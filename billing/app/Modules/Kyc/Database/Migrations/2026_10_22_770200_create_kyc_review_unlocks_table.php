<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per staff member and client; Show and Hide documents change it under a row lock (ADR 0021), so a
        // request that began earlier can never write an older state back, which a session payload could not promise.
        Schema::create('kyc_review_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation')->default(0);
            // Keyed hash of the session that opened it (never the id): another session of the same login, or the same
            // person after signing in again, has not confirmed the password and sees nothing.
            $table->string('session_hash', 64)->default('');
            // Null while hidden; a past time until the end is noticed (once), which clears the browser history.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index(['user_id', 'expires_at'], 'kyc_review_unlocks_user_expiry_index');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_review_unlocks');
    }
};
