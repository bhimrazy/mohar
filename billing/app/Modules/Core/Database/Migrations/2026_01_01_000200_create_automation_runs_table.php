<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->date('run_date');
            $table->string('task');
            $table->string('status', 20);
            $table->text('summary')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
            $table->unique(['run_date', 'task']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
