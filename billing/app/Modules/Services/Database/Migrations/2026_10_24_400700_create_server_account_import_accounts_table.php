<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (#103, #136). A fetched list is stored one row per account instead of one encrypted blob on
 * server_account_imports.accounts, which every page view and import chunk had to decrypt whole (about 80 MB at 10k
 * accounts). The columns outside `payload` are what the page filters and the import looks up by: username, domain
 * (lower case, as services.domain), the category, the matched client's id and a keyed hash of the contact email (exact
 * email search without storing the address in clear). `payload` holds the whole account row, with the contact email, and
 * is encrypted by the model's cast. Rows are deleted with their import. Lists written by the previous release stay in
 * the old column; AccountList::adopt() moves one over when it is first read, and a later release drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_account_import_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_account_import_id')->constrained('server_account_imports')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('username', 255);
            $table->string('domain', 255)->nullable();
            $table->string('email_hash', 64)->nullable();
            $table->string('category', 16);
            $table->unsignedBigInteger('client_id')->nullable();
            $table->longText('payload');
            $table->unique(['server_account_import_id', 'username'], 'sai_accounts_import_username_unique');
            $table->index(['server_account_import_id', 'position'], 'sai_accounts_import_position_index');
            $table->index(['server_account_import_id', 'category', 'position'], 'sai_accounts_import_category_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_account_import_accounts');
    }
};
