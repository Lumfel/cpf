<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campuses', function (Blueprint $table): void {
            if (! Schema::hasColumn('campuses', 'mandatory_tour_enabled')) {
                $table->boolean('mandatory_tour_enabled')->default(true)->after('is_active');
            }
        });

        Schema::table('campus_memberships', function (Blueprint $table): void {
            if (! Schema::hasColumn('campus_memberships', 'privileges_suspended_at')) {
                $table->timestamp('privileges_suspended_at')->nullable()->after('dues_confirmed_at');
            }
            if (! Schema::hasColumn('campus_memberships', 'suspension_reason')) {
                $table->string('suspension_reason', 255)->nullable()->after('privileges_suspended_at');
            }
            if (! Schema::hasColumn('campus_memberships', 'suspended_by')) {
                $table->foreignId('suspended_by')->nullable()->after('suspension_reason')->constrained('users')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('dues_audit_logs')) {
            Schema::create('dues_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('campus_membership_id')->constrained('campus_memberships')->cascadeOnDelete();
                $table->foreignId('payment_submission_id')->nullable()->constrained('payment_submissions')->nullOnDelete();
                $table->boolean('old_status');
                $table->boolean('new_status');
                $table->string('reason', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['campus_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('counselor_direct_messages')) {
            Schema::create('counselor_direct_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->string('thread_key', 64)->index();
                $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('sender_role', 40);
                $table->text('message_body');
                $table->timestamps();

                $table->index(['campus_id', 'thread_key', 'created_at'], 'cdm_campus_thread_created_idx');
            });
        }

        Schema::table('guidance_referrals', function (Blueprint $table): void {
            if (! Schema::hasColumn('guidance_referrals', 'sla_alerted_at')) {
                $table->timestamp('sla_alerted_at')->nullable()->after('counselor_confirmed_at');
            }
        });

        Schema::table('campus_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('campus_events', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campus_events', function (Blueprint $table): void {
            if (Schema::hasColumn('campus_events', 'reminder_sent_at')) {
                $table->dropColumn('reminder_sent_at');
            }
        });

        Schema::table('guidance_referrals', function (Blueprint $table): void {
            if (Schema::hasColumn('guidance_referrals', 'sla_alerted_at')) {
                $table->dropColumn('sla_alerted_at');
            }
        });

        Schema::dropIfExists('counselor_direct_messages');
        Schema::dropIfExists('dues_audit_logs');

        Schema::table('campus_memberships', function (Blueprint $table): void {
            if (Schema::hasColumn('campus_memberships', 'suspended_by')) {
                $table->dropConstrainedForeignId('suspended_by');
            }
            if (Schema::hasColumn('campus_memberships', 'suspension_reason')) {
                $table->dropColumn('suspension_reason');
            }
            if (Schema::hasColumn('campus_memberships', 'privileges_suspended_at')) {
                $table->dropColumn('privileges_suspended_at');
            }
        });

        Schema::table('campuses', function (Blueprint $table): void {
            if (Schema::hasColumn('campuses', 'mandatory_tour_enabled')) {
                $table->dropColumn('mandatory_tour_enabled');
            }
        });
    }
};
