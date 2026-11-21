<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_info_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_membership_id')->constrained('campus_memberships')->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('requested_changes');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('adviser_notes')->nullable();
            $table->timestamps();

            $table->index(['campus_id', 'status']);
            $table->index(['campus_membership_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_info_change_requests');
    }
};
