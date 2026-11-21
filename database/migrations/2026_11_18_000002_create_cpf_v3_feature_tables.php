<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('passkey_issuances')) {
            Schema::create('passkey_issuances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('issued_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('enrollment_code_hash', 128);
                $table->boolean('require_2fa')->default(true);
                $table->string('status', 24)->default('issued');
                $table->timestamp('expires_at');
                $table->timestamp('claimed_at')->nullable();
                $table->timestamps();

                $table->index(['campus_id', 'target_user_id', 'status'], 'passkey_issuances_campus_target_idx');
            });
        }

        if (! Schema::hasTable('campus_rooms')) {
            Schema::create('campus_rooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->string('room_code', 32)->default('TESTING_ROOM');
                $table->string('room_name', 100)->default('Testing Room');
                $table->unsignedTinyInteger('capacity')->default(6);
                $table->boolean('is_single_allowed_room')->default(true);
                $table->timestamps();

                $table->unique(['campus_id', 'is_single_allowed_room'], 'campus_rooms_one_room_per_campus');
            });
        }

        if (! Schema::hasTable('room_requests')) {
            Schema::create('room_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('room_id')->constrained('campus_rooms')->cascadeOnDelete();
                $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('purpose', 200);
                $table->date('request_date');
                $table->time('start_time');
                $table->time('end_time');
                $table->string('status', 24)->default('pending');
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('review_notes', 255)->nullable();
                $table->timestamps();

                $table->index(['room_id', 'request_date', 'start_time', 'end_time', 'status'], 'room_requests_slot_lookup_idx');
            });
        }

        if (! Schema::hasTable('guidance_referrals')) {
            Schema::create('guidance_referrals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('submitted_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('referral_type', 24)->default('peer_referral');
                $table->string('student_identifier', 180);
                $table->string('student_course_section', 80)->nullable();
                $table->string('suggested_priority', 24)->default('routine');
                $table->string('counselor_confirmed_priority', 24)->nullable();
                $table->string('triage_response_target', 60);
                $table->text('encrypted_concern_summary');
                $table->string('status', 32)->default('submitted');
                $table->foreignId('assigned_counselor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['campus_id', 'suggested_priority', 'status'], 'guidance_referrals_campus_priority_idx');
            });
        }

        if (! Schema::hasTable('referral_audit_logs')) {
            Schema::create('referral_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referral_id')->constrained('guidance_referrals')->cascadeOnDelete();
                $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('action', 60);
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['referral_id', 'created_at'], 'referral_audit_logs_ref_idx');
            });
        }

        if (! Schema::hasTable('officer_gc_channels')) {
            Schema::create('officer_gc_channels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->string('academic_year', 20);
                $table->string('channel_key', 120);
                $table->boolean('is_active')->default(true);
                $table->timestamp('reset_at')->nullable();
                $table->timestamps();

                $table->unique(['campus_id', 'academic_year'], 'officer_gc_channels_unique');
            });
        }

        if (! Schema::hasTable('officer_gc_messages')) {
            Schema::create('officer_gc_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('channel_id')->constrained('officer_gc_channels')->cascadeOnDelete();
                $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('sender_role', 32);
                $table->text('message_body');
                $table->string('attachment_hdd_path', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['channel_id', 'created_at'], 'officer_gc_messages_channel_time_idx');
            });
        }

        if (! Schema::hasTable('officer_gc_archives')) {
            Schema::create('officer_gc_archives', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->string('academic_year', 20);
                $table->string('zip_file_name', 160);
                $table->string('hdd_storage_path', 255);
                $table->char('sha256_checksum', 64);
                $table->unsignedInteger('total_messages')->default(0);
                $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('archived_at')->useCurrent();

                $table->index(['campus_id', 'academic_year'], 'officer_gc_archives_campus_ay_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('officer_gc_archives');
        Schema::dropIfExists('officer_gc_messages');
        Schema::dropIfExists('officer_gc_channels');
        Schema::dropIfExists('referral_audit_logs');
        Schema::dropIfExists('guidance_referrals');
        Schema::dropIfExists('room_requests');
        Schema::dropIfExists('campus_rooms');
        Schema::dropIfExists('passkey_issuances');
    }
};
