<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand only (#140). The accounts staff chose to ignore on a server's Import accounts page, remembered per server and
 * username so a later fetch lists them as ignored instead of as work to do. Usernames only (as
 * services.username); deleted with the server. A new table: no existing data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_import_ignores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('username', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['server_id', 'username'], 'server_import_ignores_server_username_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_import_ignores');
    }
};
