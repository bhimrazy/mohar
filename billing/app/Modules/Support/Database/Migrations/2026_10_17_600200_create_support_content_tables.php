<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_kb_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('support_kb_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('support_kb_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('body');
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_private')->default(false);
            $table->json('tags')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('votes_up')->default(0);
            $table->unsignedInteger('votes_down')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('support_kb_article_category', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained('support_kb_articles')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('support_kb_categories')->cascadeOnDelete();
            $table->primary(['article_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('support_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['is_published', 'published_at']);
        });

        Schema::create('support_network_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 16)->default('server');
            /** Server id owned by the Services module (no FK across modules). */
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->string('affected')->nullable();
            $table->string('priority', 16)->default('medium');
            $table->string('status', 16)->default('investigating')->index();
            $table->text('description');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('support_network_incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('support_network_incidents')->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 16);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_network_incident_updates');
        Schema::dropIfExists('support_network_incidents');
        Schema::dropIfExists('support_announcements');
        Schema::dropIfExists('support_kb_article_category');
        Schema::dropIfExists('support_kb_articles');
        Schema::dropIfExists('support_kb_categories');
    }
};
