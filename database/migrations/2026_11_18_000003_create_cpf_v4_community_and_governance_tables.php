<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Peer Digital Achievements & Certificates
        if (! Schema::hasTable('member_achievements')) {
            Schema::create('member_achievements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('title', 160);
                $table->string('category', 60)->default('Peer Facilitation');
                $table->date('achieved_on');
                $table->text('description')->nullable();
                $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['campus_id', 'user_id'], 'member_achievements_campus_user_idx');
            });
        }

        if (! Schema::hasTable('digital_certificates')) {
            Schema::create('digital_certificates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('certificate_code', 64)->unique();
                $table->string('title', 180);
                $table->string('event_name', 180);
                $table->date('issued_on');
                $table->string('file_hdd_path', 255)->nullable();
                $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['campus_id', 'user_id'], 'digital_certificates_campus_user_idx');
            });
        }

        // 2. Time Capsule
        if (! Schema::hasTable('time_capsules')) {
            Schema::create('time_capsules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('title', 160);
                $table->text('encrypted_message');
                $table->timestamp('unlock_at');
                $table->boolean('is_opened')->default(false);
                $table->timestamp('opened_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'unlock_at'], 'time_capsules_user_unlock_idx');
            });
        }

        // 3. School Year Archives (General records + Certificates + Election summaries)
        if (! Schema::hasTable('school_year_archives')) {
            Schema::create('school_year_archives', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->string('academic_year', 20);
                $table->string('archive_type', 40); // 'officer_gc', 'roster', 'elections', 'payments', 'certificates'
                $table->string('title', 180);
                $table->string('zip_file_name', 180);
                $table->string('hdd_storage_path', 255);
                $table->char('sha256_checksum', 64);
                $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('archived_at')->useCurrent();
                $table->index(['campus_id', 'academic_year', 'archive_type'], 'sy_archives_lookup_idx');
            });
        }

        // 4. Online Voting (Campus Elections)
        if (! Schema::hasTable('elections')) {
            Schema::create('elections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->string('academic_year', 20);
                $table->string('title', 180);
                $table->string('status', 24)->default('draft'); // 'draft', 'open', 'closed'
                $table->timestamp('opens_at')->nullable();
                $table->timestamp('closes_at')->nullable();
                $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('election_candidates')) {
            Schema::create('election_candidates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('position', 60); // 'president', 'vice_president', 'secretary', 'treasurer'
                $table->text('platform')->nullable();
                $table->timestamps();
                $table->unique(['election_id', 'user_id', 'position'], 'election_candidates_unique');
            });
        }

        if (! Schema::hasTable('election_votes')) {
            Schema::create('election_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
                $table->foreignId('candidate_id')->constrained('election_candidates')->cascadeOnDelete();
                $table->foreignId('voter_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('position', 60);
                $table->timestamp('cast_at')->useCurrent();
                // Strictly 1 vote per verified member per position per election
                $table->unique(['election_id', 'voter_user_id', 'position'], 'election_votes_one_per_position');
            });
        }

        // 5. Payment Validation (Treasurer + Adviser Destination Oversight)
        if (! Schema::hasTable('payment_destinations')) {
            Schema::create('payment_destinations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->string('channel', 40); // 'GCash', 'Maya', 'Cash', 'Bank'
                $table->string('account_name', 140);
                $table->string('account_number', 80);
                $table->boolean('is_active')->default(true);
                $table->foreignId('approved_by_adviser_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payment_submissions')) {
            Schema::create('payment_submissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('destination_id')->nullable()->constrained('payment_destinations')->nullOnDelete();
                $table->string('purpose', 140); // e.g. 'Membership Fee AY 2026-2027'
                $table->decimal('amount', 10, 2);
                $table->string('reference_number', 100);
                $table->string('receipt_hdd_path', 255)->nullable();
                $table->string('status', 24)->default('pending'); // 'pending', 'validated', 'rejected'
                $table->foreignId('validated_by_treasurer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('validated_at')->nullable();
                $table->string('treasurer_notes', 255)->nullable();
                $table->timestamps();
                $table->index(['campus_id', 'status'], 'payment_submissions_campus_status_idx');
            });
        }

        // 6. Quoting System
        if (! Schema::hasTable('peer_quotes')) {
            Schema::create('peer_quotes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete();
                $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('quote_text');
                $table->string('author_name', 120)->default('CPF Peer Circle');
                $table->string('mood_tag', 40)->default('Calm');
                $table->boolean('is_approved')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('peer_quotes');
        Schema::dropIfExists('payment_submissions');
        Schema::dropIfExists('payment_destinations');
        Schema::dropIfExists('election_votes');
        Schema::dropIfExists('election_candidates');
        Schema::dropIfExists('elections');
        Schema::dropIfExists('school_year_archives');
        Schema::dropIfExists('time_capsules');
        Schema::dropIfExists('digital_certificates');
        Schema::dropIfExists('member_achievements');
    }
};
