<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitations for new client logins: a single-use token (only its SHA-256 is stored) that sets the first password
     * of a login nobody has used. One open invitation per login and account; sending a new one replaces the token, so
     * the old link stops working. email and sent_at are what the link was mailed to and when: the link stops working
     * when the login's email differs, or it got a password, two-factor authentication or a sign-in some other way.
     */
    public function up(): void
    {
        Schema::create('client_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('email');
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invited_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_invitations');
    }
};
