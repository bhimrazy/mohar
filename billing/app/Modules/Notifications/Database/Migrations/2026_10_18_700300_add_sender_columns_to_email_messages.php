<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->string('from_name')->nullable()->after('from');
            $table->string('reply_to')->nullable()->after('from_name');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn(['from_name', 'reply_to']);
        });
    }
};
