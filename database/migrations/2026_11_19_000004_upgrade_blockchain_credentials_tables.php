<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Immutable Blockchain Credential Ledger (Hash-Chained + EVM-Ready Anchoring)
        if (! Schema::hasTable('blockchain_credential_ledgers')) {
            Schema::create('blockchain_credential_ledgers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('block_index')->unique();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('issuer_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('credential_type', 32); // 'achievement', 'certificate', 'award'
                $table->unsignedBigInteger('credential_id');
                $table->string('credential_code', 64)->index();
                $table->json('canonical_payload');
                $table->char('payload_hash', 64)->unique();
                $table->char('previous_block_hash', 64);
                $table->char('block_hash', 64)->unique();
                $table->string('driver', 32)->default('local_ledger');
                $table->string('network', 64)->default('cpf-local-chain');
                $table->string('transaction_hash', 128)->nullable()->index();
                $table->string('contract_address', 128)->nullable();
                $table->string('status', 24)->default('anchored'); // 'pending', 'anchored', 'verified', 'revoked'
                $table->timestamp('anchored_at');
                $table->timestamp('revoked_at')->nullable();
                $table->string('revocation_reason', 255)->nullable();
                $table->timestamps();

                $table->index(['credential_type', 'credential_id'], 'bcl_credential_lookup_idx');
                $table->index(['campus_id', 'recipient_user_id'], 'bcl_campus_recipient_idx');
                $table->index(['status', 'network'], 'bcl_status_network_idx');
            });
        }

        // 2. New Member Awards Table (Anchored on Blockchain Ledger)
        if (! Schema::hasTable('member_awards')) {
            Schema::create('member_awards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('award_code', 64)->unique();
                $table->string('title', 180);
                $table->string('award_category', 80)->default('Peer Service');
                $table->string('academic_year', 20)->default('2026-2027');
                $table->date('awarded_on');
                $table->text('citation')->nullable();
                $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('blockchain_status', 24)->default('unanchored');
                $table->char('credential_hash', 64)->nullable()->index();
                $table->foreignId('blockchain_ledger_id')
                    ->nullable()
                    ->constrained('blockchain_credential_ledgers')
                    ->nullOnDelete();
                $table->timestamps();

                $table->index(['campus_id', 'user_id'], 'member_awards_campus_user_idx');
            });
        }

        // 3. Ensure member_achievements Exists and Has Blockchain Anchoring Columns
        if (! Schema::hasTable('member_achievements')) {
            Schema::create('member_achievements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('achievement_code', 64)->nullable()->unique();
                $table->string('title', 160);
                $table->string('category', 60)->default('Peer Facilitation');
                $table->date('achieved_on');
                $table->text('description')->nullable();
                $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('blockchain_status', 24)->default('unanchored');
                $table->char('credential_hash', 64)->nullable()->index();
                $table->foreignId('blockchain_ledger_id')
                    ->nullable()
                    ->constrained('blockchain_credential_ledgers')
                    ->nullOnDelete();
                $table->timestamps();
                $table->index(['campus_id', 'user_id'], 'member_achievements_campus_user_idx');
            });
        } else {
            Schema::table('member_achievements', function (Blueprint $table) {
                if (! Schema::hasColumn('member_achievements', 'achievement_code')) {
                    $table->string('achievement_code', 64)->nullable()->unique()->after('user_id');
                }
                if (! Schema::hasColumn('member_achievements', 'blockchain_status')) {
                    $table->string('blockchain_status', 24)->default('unanchored')->after('verified_by_user_id');
                }
                if (! Schema::hasColumn('member_achievements', 'credential_hash')) {
                    $table->char('credential_hash', 64)->nullable()->index()->after('blockchain_status');
                }
                if (! Schema::hasColumn('member_achievements', 'blockchain_ledger_id')) {
                    $table->foreignId('blockchain_ledger_id')
                        ->nullable()
                        ->after('credential_hash')
                        ->constrained('blockchain_credential_ledgers')
                        ->nullOnDelete();
                }
            });
        }

        // 4. Ensure digital_certificates Exists and Has Blockchain Anchoring Columns
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
                $table->string('blockchain_status', 24)->default('unanchored');
                $table->char('credential_hash', 64)->nullable()->index();
                $table->foreignId('blockchain_ledger_id')
                    ->nullable()
                    ->constrained('blockchain_credential_ledgers')
                    ->nullOnDelete();
                $table->timestamps();
                $table->index(['campus_id', 'user_id'], 'digital_certificates_campus_user_idx');
            });
        } else {
            Schema::table('digital_certificates', function (Blueprint $table) {
                if (! Schema::hasColumn('digital_certificates', 'blockchain_status')) {
                    $table->string('blockchain_status', 24)->default('unanchored')->after('issued_by_user_id');
                }
                if (! Schema::hasColumn('digital_certificates', 'credential_hash')) {
                    $table->char('credential_hash', 64)->nullable()->index()->after('blockchain_status');
                }
                if (! Schema::hasColumn('digital_certificates', 'blockchain_ledger_id')) {
                    $table->foreignId('blockchain_ledger_id')
                        ->nullable()
                        ->after('credential_hash')
                        ->constrained('blockchain_credential_ledgers')
                        ->nullOnDelete();
                }
            });
        }

        // 5. Public & Officer Verification Audit Logs
        if (! Schema::hasTable('blockchain_verification_logs')) {
            Schema::create('blockchain_verification_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ledger_id')
                    ->nullable()
                    ->constrained('blockchain_credential_ledgers')
                    ->cascadeOnDelete();
                $table->string('queried_identifier', 128)->index();
                $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('verifier_ip', 45)->nullable();
                $table->string('verification_result', 24); // 'valid', 'tampered', 'revoked', 'not_found'
                $table->timestamp('checked_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blockchain_verification_logs');

        if (Schema::hasTable('digital_certificates') && Schema::hasColumn('digital_certificates', 'blockchain_ledger_id')) {
            Schema::table('digital_certificates', function (Blueprint $table) {
                $table->dropConstrainedForeignId('blockchain_ledger_id');
                $table->dropColumn(['blockchain_status', 'credential_hash']);
            });
        }

        if (Schema::hasTable('member_achievements') && Schema::hasColumn('member_achievements', 'blockchain_ledger_id')) {
            Schema::table('member_achievements', function (Blueprint $table) {
                $table->dropConstrainedForeignId('blockchain_ledger_id');
                $table->dropColumn(['achievement_code', 'blockchain_status', 'credential_hash']);
            });
        }

        Schema::dropIfExists('member_awards');
        Schema::dropIfExists('blockchain_credential_ledgers');
    }
};
