<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->index('staff_id');
            $table->index('client_id');
            $table->index('user_id');
        });

        Schema::table('staff_invitations', function (Blueprint $table) {
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('staff_invitations', function (Blueprint $table) {
            $table->dropIndex(['role_id']);
        });

        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->dropIndex(['staff_id']);
            $table->dropIndex(['client_id']);
            $table->dropIndex(['user_id']);
        });
    }
};
