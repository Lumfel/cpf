<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('current_academic_year', 20)->default('2026-2027');
            $table->string('adviser_name', 150)->nullable();
            $table->string('gc_group_name', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('campus_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();

            $table->string('role', 32)->default('member');
            $table->string('status', 32)->default('pending');
            $table->string('enrollment_source', 32)->default('online_form');

            $table->string('recorded_email');
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->string('full_name_last_first_mi', 180);
            $table->string('course_year_section', 80);
            $table->string('sex', 20);
            $table->string('gender_identity', 40)->nullable();
            $table->string('gender_identity_other', 100)->nullable();
            $table->date('birthdate');
            $table->string('active_email');
            $table->string('active_fb_account');
            $table->string('cellphone_number', 40);

            $table->foreignId('added_or_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->boolean('is_in_messenger_gc')->default(false);
            $table->foreignId('gc_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('gc_confirmed_at')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'campus_id'], 'campus_memberships_user_campus_unique');
            $table->index(['campus_id', 'status', 'last_name', 'first_name'], 'campus_memberships_campus_status_az_idx');
            $table->index(['campus_id', 'role'], 'campus_memberships_campus_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_memberships');
        Schema::dropIfExists('campuses');
    }
};
