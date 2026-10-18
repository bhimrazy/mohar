<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns from the platform-admin spec (§2) on Core-owned tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role_id');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->timestamp('invited_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
            $table->boolean('is_system')->default(false)->after('permissions');
        });

        Schema::table('client_groups', function (Blueprint $table) {
            $table->boolean('exempt_from_suspension')->default(false);
            $table->boolean('separate_invoices')->default(false);
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->unsignedTinyInteger('decimals')->default(2)->after('suffix');
            $table->string('format', 20)->default('1,234.56')->after('decimals');
            $table->boolean('is_auto_rate')->default(false)->after('exchange_rate');
            $table->timestamp('rate_updated_at')->nullable()->after('is_auto_rate');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('merged_into_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('actor_type', 20)->nullable();
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->nullable()->index();
            $table->string('user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
            $table->dropIndex(['event']);
            $table->dropColumn(['actor_type', 'event', 'user_agent']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_client_id');
            $table->dropColumn('closed_at');
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn(['decimals', 'format', 'is_auto_rate', 'rate_updated_at']);
        });

        Schema::table('client_groups', function (Blueprint $table) {
            $table->dropColumn(['exempt_from_suspension', 'separate_invoices']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['description', 'is_system']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'last_login_ip', 'invited_at', 'must_change_password', 'password_changed_at']);
        });
    }
};
