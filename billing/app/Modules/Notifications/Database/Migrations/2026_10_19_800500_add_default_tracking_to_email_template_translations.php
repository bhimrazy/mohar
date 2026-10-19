<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * default_hash: the shipped default this text started from (TemplateCatalog::hash), so an upgrade can tell edited
     * text from unedited. previous_text: the staff text kept when "Use new default" replaced it.
     */
    public function up(): void
    {
        Schema::table('email_template_translations', function (Blueprint $table) {
            $table->string('default_hash', 64)->nullable()->after('action_label');
            $table->json('previous_text')->nullable()->after('default_hash');
        });
    }

    public function down(): void
    {
        Schema::table('email_template_translations', function (Blueprint $table) {
            $table->dropColumn(['default_hash', 'previous_text']);
        });
    }
};
