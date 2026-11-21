<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campus_counselors')) {
            Schema::create('campus_counselors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('title', 120);
                $table->string('specialty', 140)->nullable();
                $table->json('weekly_vacant_slots');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['campus_id', 'is_active'], 'campus_counselors_campus_active_idx');
            });
        }

        if (Schema::hasTable('room_requests') && ! Schema::hasColumn('room_requests', 'counselor_id')) {
            Schema::table('room_requests', function (Blueprint $table) {
                $table->foreignId('counselor_id')
                    ->nullable()
                    ->after('room_id')
                    ->constrained('campus_counselors')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('room_requests') && Schema::hasColumn('room_requests', 'counselor_id')) {
            Schema::table('room_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('counselor_id');
            });
        }

        Schema::dropIfExists('campus_counselors');
    }
};
