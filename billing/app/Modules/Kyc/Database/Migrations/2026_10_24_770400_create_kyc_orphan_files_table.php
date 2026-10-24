<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity image files the daily check found without a document (#298). Staff delete them; the check never does.
 * client_id is the id in the path, with no foreign key: the client may no longer exist. Deletes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_orphan_files', function (Blueprint $table) {
            $table->id();
            $table->string('path', 100)->unique();
            $table->unsignedBigInteger('client_id')->index();
            $table->timestamp('modified_at')->nullable();
            $table->timestamp('found_at');
            $table->timestamp('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_orphan_files');
    }
};
