<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('description')->nullable();
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->json('events');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('disabled_reason')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 80)->index();
            $table->json('payload');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->string('status', 20);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'webhook_endpoint_id']);
            $table->index('created_at');
            $table->index(['webhook_endpoint_id', 'id']);          // endpoint delivery log (also covers the FK)
        });

        // notifications:retry-webhooks scans only due, unfinished deliveries: a partial index where the
        // database supports one, else a composite.
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE INDEX webhook_deliveries_due_index ON webhook_deliveries (next_attempt_at, id) WHERE status IN ('pending', 'failed') AND next_attempt_at IS NOT NULL");
        } else {
            Schema::table('webhook_deliveries', fn (Blueprint $table) => $table->index(['status', 'next_attempt_at'], 'webhook_deliveries_due_index'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
