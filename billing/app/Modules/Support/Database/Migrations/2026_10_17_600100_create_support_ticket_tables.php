<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('email')->unique();
            $table->boolean('is_hidden')->default(false);
            $table->boolean('clients_only')->default(false);
            $table->boolean('replies_only_by_email')->default(false);
            $table->boolean('send_autoresponse')->default(true);
            $table->text('autoresponse_message')->nullable();
            $table->boolean('prevent_client_close')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('support_department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('support_departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('notify_new')->default(true);
            $table->boolean('notify_replies')->default(true);
            $table->unique(['department_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('access_key', 64);
            $table->foreignId('department_id')->constrained('support_departments')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requester_name');
            $table->string('requester_email');
            $table->string('subject');
            $table->string('status', 32)->default('open')->index();
            $table->string('priority', 16)->default('medium');
            $table->string('source', 16)->default('web');
            /** Ids owned by the Services/Domains modules (no FK across modules). */
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->unsignedBigInteger('domain_id')->nullable()->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('cc')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('prevent_client_close')->default(false);
            $table->foreignId('merged_into_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('first_staff_reply_at')->nullable();
            $table->timestamp('last_reply_at')->nullable()->index();
            $table->timestamp('last_client_reply_at')->nullable();
            $table->timestamp('last_staff_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 16)->nullable();
            $table->timestamps();
            $table->index(['status', 'last_reply_at']);
            $table->index('requester_email');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('type', 16)->default('reply');
            $table->string('author_type', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');
            $table->string('author_email')->nullable();
            $table->longText('body');
            $table->longText('body_html');
            $table->string('source', 16)->default('web');
            $table->string('email_message_id')->nullable()->unique();
            $table->boolean('is_autoresponse')->default(false);
            $table->boolean('is_unverified_sender')->default(false);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('support_ticket_messages')->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('pruned_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('ticket_id');
        });

        Schema::create('support_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor', 16);
            $table->string('type', 32);
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->json('meta')->nullable();
            /** Ticket version after the change (collision detection summary). */
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->index(['ticket_id', 'version']);
        });

        Schema::create('support_canned_reply_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('support_canned_reply_categories')->nullOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('support_canned_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('support_canned_reply_categories')->nullOnDelete();
            $table->string('name');
            $table->text('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('support_inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('message_id')->unique();
            $table->string('from');
            $table->string('to')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 32)->index();
            $table->string('reason')->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->string('raw_path')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();
            $table->index(['from', 'received_at']);
        });

        Schema::create('support_staff_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('signature');
            $table->timestamps();
        });

        Schema::create('support_spam_rules', function (Blueprint $table) {
            $table->id();
            $table->string('field', 16);
            $table->string('match', 16);
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_spam_rules');
        Schema::dropIfExists('support_staff_signatures');
        Schema::dropIfExists('support_inbound_emails');
        Schema::dropIfExists('support_canned_replies');
        Schema::dropIfExists('support_canned_reply_categories');
        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_ticket_attachments');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_department_user');
        Schema::dropIfExists('support_departments');
    }
};
