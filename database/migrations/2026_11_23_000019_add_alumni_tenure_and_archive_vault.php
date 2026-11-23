<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campus_memberships', function (Blueprint $table): void {
            $table->timestamp('alumni_since')->nullable()->after('dues_confirmed_at');
            $table->string('alumni_reason', 120)->nullable()->after('alumni_since');
            $table->foreignId('alumni_transitioned_by')
                ->nullable()
                ->after('alumni_reason')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('club_service_tenures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->foreignId('campus_membership_id')->constrained('campus_memberships')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('academic_year', 20);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 80)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verification_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['campus_id', 'user_id'], 'club_service_tenures_campus_user_idx');
            $table->index(['campus_membership_id', 'ended_at'], 'club_service_tenures_membership_ended_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_service_tenures');

        Schema::table('campus_memberships', function (Blueprint $table): void {
            $table->dropForeign(['alumni_transitioned_by']);
            $table->dropColumn(['alumni_since', 'alumni_reason', 'alumni_transitioned_by']);
        });
    }
};
