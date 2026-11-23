<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campus_events', function (Blueprint $table): void {
            $table->boolean('reflection_enabled')->default(false)->after('status');
            $table->boolean('pending_reflection_enabled')->nullable()->after('reflection_enabled');
            $table->foreignId('reflection_requested_by_user_id')
                ->nullable()
                ->after('pending_reflection_enabled')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reflection_requested_at')->nullable()->after('reflection_requested_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('campus_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reflection_requested_by_user_id');
            $table->dropColumn([
                'reflection_enabled',
                'pending_reflection_enabled',
                'reflection_requested_at',
            ]);
        });
    }
};
