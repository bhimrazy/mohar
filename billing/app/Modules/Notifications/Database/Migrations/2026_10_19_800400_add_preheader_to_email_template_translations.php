<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The inbox preview line, written per template and locale (design §6). Empty = the opening line is used.
     */
    public function up(): void
    {
        Schema::table('email_template_translations', function (Blueprint $table) {
            $table->string('preheader', 255)->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('email_template_translations', function (Blueprint $table) {
            $table->dropColumn('preheader');
        });
    }
};
