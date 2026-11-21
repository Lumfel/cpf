<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('location', 160)->nullable();
            $table->date('event_date');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('status', 24)->default('pending_approval');
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['campus_id', 'event_date']);
        });

        Schema::create('campus_event_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_event_id')->constrained('campus_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('logged_in_at')->nullable();
            $table->foreignId('logged_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('logged_out_at')->nullable();
            $table->foreignId('logged_out_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campus_event_id', 'user_id']);
        });

        Schema::create('campus_event_reflections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_event_id')->constrained('campus_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('time_capsule_id')->constrained('time_capsules')->cascadeOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['campus_event_id', 'user_id']);
        });

        Schema::create('campus_event_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campus_event_id')->constrained('campus_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 180);
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_event_notifications');
        Schema::dropIfExists('campus_event_reflections');
        Schema::dropIfExists('campus_event_attendances');
        Schema::dropIfExists('campus_events');
    }
};
