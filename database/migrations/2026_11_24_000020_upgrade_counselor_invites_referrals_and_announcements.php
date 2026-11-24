<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('counselor_invitations')) {
            Schema::create('counselor_invitations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('email')->index();
                $table->string('counselor_name', 180)->nullable();
                $table->string('token', 64)->unique();
                $table->string('otp_hash');
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('guidance_referrals', function (Blueprint $table) {
            if (! Schema::hasColumn('guidance_referrals', 'feedback_status')) {
                $table->string('feedback_status', 24)->default('pending')->after('status');
            }
            if (! Schema::hasColumn('guidance_referrals', 'safe_scheduling_note')) {
                $table->string('safe_scheduling_note', 500)->nullable()->after('feedback_status');
            }
            if (! Schema::hasColumn('guidance_referrals', 'confidential_clinical_notes')) {
                $table->text('confidential_clinical_notes')->nullable()->after('safe_scheduling_note');
            }
            if (! Schema::hasColumn('guidance_referrals', 'counselor_id')) {
                $table->foreignId('counselor_id')->nullable()->after('assigned_counselor_id')->constrained('campus_counselors')->nullOnDelete();
            }
            if (! Schema::hasColumn('guidance_referrals', 'room_request_id')) {
                $table->foreignId('room_request_id')->nullable()->after('counselor_id')->constrained('room_requests')->nullOnDelete();
            }
            if (! Schema::hasColumn('guidance_referrals', 'preferred_date')) {
                $table->date('preferred_date')->nullable()->after('room_request_id');
            }
            if (! Schema::hasColumn('guidance_referrals', 'preferred_start_time')) {
                $table->time('preferred_start_time')->nullable()->after('preferred_date');
            }
            if (! Schema::hasColumn('guidance_referrals', 'preferred_end_time')) {
                $table->time('preferred_end_time')->nullable()->after('preferred_start_time');
            }
        });

        if (! Schema::hasTable('campus_announcements')) {
            Schema::create('campus_announcements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('published_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('publisher_role', 40);
                $table->string('template_key', 50);
                $table->string('title', 180);
                $table->text('body');
                $table->string('schedule_label', 140)->nullable();
                $table->string('location', 140)->nullable();
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('published_at');
                $table->timestamps();

                $table->index(['campus_id', 'is_pinned', 'published_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_announcements');

        Schema::table('guidance_referrals', function (Blueprint $table) {
            foreach (['room_request_id', 'counselor_id'] as $fkColumn) {
                if (Schema::hasColumn('guidance_referrals', $fkColumn)) {
                    $table->dropConstrainedForeignId($fkColumn);
                }
            }

            $columns = array_filter(
                ['feedback_status', 'safe_scheduling_note', 'confidential_clinical_notes', 'preferred_date', 'preferred_start_time', 'preferred_end_time'],
                fn (string $col) => Schema::hasColumn('guidance_referrals', $col),
            );

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::dropIfExists('counselor_invitations');
    }
};
