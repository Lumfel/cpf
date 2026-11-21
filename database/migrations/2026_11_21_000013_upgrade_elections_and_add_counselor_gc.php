<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('counselor_gc_messages')) {
            Schema::create('counselor_gc_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('sender_role', 40);
                $table->text('message_body');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['campus_id', 'created_at'], 'counselor_gc_messages_campus_time_idx');
            });
        }

        if (Schema::hasTable('elections')) {
            Schema::table('elections', function (Blueprint $table) {
                if (! Schema::hasColumn('elections', 'current_position')) {
                    $table->string('current_position', 60)->nullable()->default('president')->after('status');
                }
                if (! Schema::hasColumn('elections', 'current_phase')) {
                    $table->string('current_phase', 24)->default('voting')->after('current_position');
                }
                if (! Schema::hasColumn('elections', 'round_number')) {
                    $table->unsignedTinyInteger('round_number')->default(1)->after('current_phase');
                }
                if (! Schema::hasColumn('elections', 'position_results')) {
                    $table->json('position_results')->nullable()->after('round_number');
                }
            });
        }

        if (Schema::hasTable('election_candidates')) {
            Schema::table('election_candidates', function (Blueprint $table) {
                if (! Schema::hasColumn('election_candidates', 'nominated_by_user_id')) {
                    $table->foreignId('nominated_by_user_id')->nullable()->after('platform')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('election_candidates', 'nomination_status')) {
                    $table->string('nomination_status', 20)->default('accepted')->after('nominated_by_user_id');
                }
                if (! Schema::hasColumn('election_candidates', 'is_tie_contender')) {
                    $table->boolean('is_tie_contender')->default(false)->after('nomination_status');
                }
            });
        }

        if (Schema::hasTable('election_votes')) {
            Schema::table('election_votes', function (Blueprint $table) {
                if (! Schema::hasColumn('election_votes', 'round_number')) {
                    $table->unsignedTinyInteger('round_number')->default(1)->after('position');
                }
            });

            Schema::table('election_votes', function (Blueprint $table) {
                $table->index('election_id', 'election_votes_election_id_idx');
                $table->dropUnique('election_votes_one_per_position');
                $table->unique(['election_id', 'voter_user_id', 'position', 'round_number'], 'election_votes_one_per_position_round');
            });
        }

        if (! Schema::hasTable('election_opt_outs')) {
            Schema::create('election_opt_outs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('opted_out_at')->useCurrent();
                $table->timestamps();

                $table->unique(['election_id', 'user_id'], 'election_opt_outs_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('election_opt_outs');

        if (Schema::hasTable('election_votes')) {
            Schema::table('election_votes', function (Blueprint $table) {
                $table->dropUnique('election_votes_one_per_position_round');
                $table->unique(['election_id', 'voter_user_id', 'position'], 'election_votes_one_per_position');
                $table->dropIndex('election_votes_election_id_idx');
                $table->dropColumn('round_number');
            });
        }

        if (Schema::hasTable('election_candidates')) {
            Schema::table('election_candidates', function (Blueprint $table) {
                $table->dropConstrainedForeignId('nominated_by_user_id');
                $table->dropColumn(['nomination_status', 'is_tie_contender']);
            });
        }

        if (Schema::hasTable('elections')) {
            Schema::table('elections', function (Blueprint $table) {
                $table->dropColumn(['current_position', 'current_phase', 'round_number', 'position_results']);
            });
        }

        Schema::dropIfExists('counselor_gc_messages');
    }
};
