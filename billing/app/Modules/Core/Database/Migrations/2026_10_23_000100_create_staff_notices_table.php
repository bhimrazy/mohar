<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time notices shown to staff on the dashboard until each person dismisses them. A row exists only for staff
 * who were there when the notice was introduced, so a fresh install or a later hire never sees an upgrade notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('notice', 100);
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'notice']);
        });

        $now = now();

        DB::table('users')->where('is_staff', true)->orderBy('id')->chunkById(500, function ($staff) use ($now): void {
            DB::table('staff_notices')->insert($staff->map(fn (object $user): array => [
                'user_id' => $user->id,
                'notice' => 'company_time_settings',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notices');
    }
};
