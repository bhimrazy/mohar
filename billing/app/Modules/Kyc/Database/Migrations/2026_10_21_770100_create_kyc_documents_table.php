<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->text('number');
            $table->string('front_path', 100);
            $table->string('front_mime', 20);
            $table->string('back_path', 100)->nullable();
            $table->string('back_mime', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('decline_reason', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->date('delete_after')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'superseded_at'], 'kyc_documents_client_current_index');
            $table->index(['delete_after', 'id'], 'kyc_documents_delete_after_index');
            $table->index(['status', 'superseded_at', 'created_at'], 'kyc_documents_queue_index');
            $table->index('uploaded_by');
            $table->index('decided_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};
