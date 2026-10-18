<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('type', 20)->index();
            $table->string('audience', 10);
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->boolean('attach_invoice_pdf')->default(false);
            $table->boolean('attach_quote_pdf')->default(false);
            $table->boolean('plain_text_only')->default(false);
            $table->timestamps();
        });

        Schema::create('email_template_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('subject', 255);
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['email_template_id', 'locale']);
        });

        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('dedupe_key', 40)->unique();
            $table->string('template_key', 120)->nullable()->index();
            // client_id is covered by the (client_id, …) composites below.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->index()->constrained('users')->nullOnDelete();
            // Primary recipient (lowercase) for search; "to" holds the full list.
            $table->string('recipient')->index();
            $table->json('to');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('from')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('subject', 255);
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->boolean('has_redactions')->default(false);
            $table->string('locale', 10)->default('en');
            $table->string('related_type', 40)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('status', 20)->index();
            $table->string('suppressed_reason', 100)->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('body_purged_at')->nullable();
            $table->timestamps();
            $table->index(['related_type', 'related_id']);
            $table->index('created_at');
            $table->index(['client_id', 'id']);                    // admin log filtered by client (newest first)
            $table->index(['client_id', 'status', 'sent_at']);     // client email history
            $table->index(['status', 'updated_at']);               // failed-email dashboard to-do
            $table->index(['body_purged_at', 'created_at']);       // retention purge
        });

        Schema::create('client_email_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained()->cascadeOnDelete();
            // "owner" for the account's primary address, "user:<id>" for other client-area logins.
            $table->string('scope_key', 40);
            $table->json('categories');
            $table->timestamps();
            $table->unique(['client_id', 'scope_key']);
        });

        Schema::create('role_email_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('categories');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_email_subscriptions');
        Schema::dropIfExists('client_email_preferences');
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('email_template_translations');
        Schema::dropIfExists('email_templates');
    }
};
